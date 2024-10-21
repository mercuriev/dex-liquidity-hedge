const {
    ethers,
    provider
} = require('../config/ethers');
const { Token, ChainId, JSBI, Percent } =  require('@uniswap/sdk');
const { Pool: V3Pool, Position, tickToPrice, NonfungiblePositionManager, UniswapV3Pool} = require('@uniswap/v3-sdk');
const IUniswapV3PoolABI = require('@uniswap/v3-core/artifacts/contracts/interfaces/IUniswapV3Pool.sol/IUniswapV3Pool.json').abi;
const { alchemy } = require('../config/alchemy');
const { Utils } = require('alchemy-sdk');
const { PositionManager } = require('./PositionManager');
const { wallet } = require('../config/wallet');
const { MasterChef } = require('../config/pancake');
const { buildToken } = require('./Token');

class Pool {
    constructor(address) {
        this.address = address;
    }

    static async factory(address) {
        const self = new Pool(address);
        self.contract = new ethers.Contract(address, IUniswapV3PoolABI, provider);
        self.token0 = await buildToken(await self.contract.token0());
        self.token1 = await buildToken(await self.contract.token1());
        self.symbol = self.token0.symbol + '-' + self.token1.symbol;
        self.fee    = Number(await self.contract.fee());
        return self;
    }

    async getState(forceRefresh = false)
    {
        if (!forceRefresh && this.state) return this.state;

        const slot0 = await this.contract.slot0();
        const liquidity = await this.contract.liquidity();

        return this.state = new V3Pool(this.token0, this.token1, this.fee, String(slot0[0]), String(liquidity), Number(slot0[1]));
    }

    async getCurrentPrice() {
        const state = await this.getState();
        return state.token0Price.toFixed(2);
    }

    async approve(spender) {
        const maxAllowance = ethers.MaxUint256;

        const allowanceToken0 = await this.token0.contract.allowance(wallet.address, spender);
        if (allowanceToken0 === 0n) {
            await this.token0.contract.approve(spender, maxAllowance);
        }
        const allowanceToken1 = await this.token1.contract.allowance(wallet.address, spender);
        if (allowanceToken1 === 0n) {
            await this.token1.contract.approve(spender, maxAllowance);
        }
    }

    async mint(amount0, amount1, low, high)
    {
        // fetch current pool state (price and tick)
        const [liquidity, slot0] = await Promise.all([
            this.contract.liquidity(),
            this.contract.slot0()
        ]);

        const poolState = new V3Pool(
            this.token0,
            this.token1,
            this.fee,
            slot0.sqrtPriceX96.toString(),
            liquidity.toString(),
            Number(slot0.tick)
        );

        const [tickLower, tickHigher] = this.findTicksForPrices(poolState, slot0, low, high);
        if (tickLower === tickHigher) throw new Error('Invalid tick range: same tick');

        const position = Position.fromAmounts({
            pool: poolState,
            tickLower: tickLower,
            tickUpper: tickHigher,
            amount0: JSBI.BigInt(amount0 * Math.pow(10, this.token0.decimals)),
            amount1: JSBI.BigInt(amount1 * Math.pow(10, this.token1.decimals)),
            useFullPrecision: true
        });

        const mintOptions = {
            recipient: wallet.address,
            deadline: Math.floor(Date.now() / 1000) + 60 * 20,
            slippageTolerance: new Percent(50, 10_000),
        };
        // TODO check allowance first
        await this.approve(PositionManager.target);
        const { calldata, value } = NonfungiblePositionManager.addCallParameters(position, mintOptions);
        const transaction = {
            to: PositionManager.target,
            from: wallet.address,
            data: calldata,
            value: value,
            maxFeePerGas: '10000000',
            maxPriorityFeePerGas: '10000000',
        };

        return await wallet.sendTransaction(transaction);
    }

    async stake(tokenId)
    {
        const params = NonfungiblePositionManager.safeTransferFromParameters({
            sender: wallet.address,
            recipient: MasterChef.target,
            tokenId: tokenId
        });
        const tx = {
            to: PositionManager.target,
            from: wallet.address,
            data: params.calldata,
            value: params.value
        }
        return wallet.sendTransaction(tx);
    }

    async withdraw(tokenId)
    {
        const position = await PositionManager.positions(tokenId);
        if (position[7] === 0n) throw new Error('Position already withdrawn');

        let calls = []; // it's a multicall
        calls.push(await MasterChef.getFunction('decreaseLiquidity').populateTransaction([
            tokenId,
            position[7],
            position[10],
            position[11],
            Math.floor(Date.now() / 1000) + 60 * 20
        ]));
        calls.push(await MasterChef.getFunction('collect').populateTransaction([
            tokenId,
            wallet.address,
            BigInt(2 ** 127),
            BigInt(2 ** 127)
        ]));

        // if NOT STAKED then call ntfManager, if staked MasterChef
        const owner = await PositionManager.ownerOf(tokenId);
        if (owner === MasterChef.target) {
            return MasterChef.multicall(calls.map(call => call.data));
        } else {
            return PositionManager.multicall(calls.map(call => call.data));
        }
    }

    /**
     * Return ticks that are usable for minting a position with the given prices.
     * The prices are given as float token1 / token0 (reverse!)
     *
     * @param poolContract
     * @param slot0
     * @param low
     * @param high
     */
    findTicksForPrices(poolContract, slot0, low, high)
    {
        // hardcode the range of ticks to search for the given prices for efficiency
        const lowestTick = Number(slot0.tick) - 1000 * poolContract.tickSpacing;
        const highestTick = Number(slot0.tick) + 1000 * poolContract.tickSpacing;
        let lowTick = null;
        let highTick = null;
        for (let tick = lowestTick; tick < highestTick; tick += poolContract.tickSpacing) {
            const price = tickToPrice(this.token0, this.token1, tick).toSignificant();
            if (lowTick === null && price > low) {
                lowTick = tick;
                if (Number(high) === 0) {
                    lowTick += poolContract.tickSpacing;
                }
            }
            if (highTick === null && price > high) {
                highTick = tick;
                if (Number(low) === 0) {
                    highTick -= poolContract.tickSpacing;
                }
            }
        }
        return [lowTick, highTick];
    }

    observePrice(callback) {
        provider.on('block', block => {
            this.contract.slot0().then(slot0 => {
                const tick = Number(slot0.tick);
                const price = tickToPrice(this.token0, this.token1, tick);
                callback(price, tick, block);
            });
        });
    }

    /// @notice Emitted by the pool for any swaps between token0 and token1
    /// @param sender The address that initiated the swap call, and that received the callback
    /// @param recipient The address that received the output of the swap
    /// @param amount0 The delta of the token0 balance of the pool
    /// @param amount1 The delta of the token1 balance of the pool
    /// @param sqrtPriceX96 The sqrt(price) of the pool after the swap, as a Q64.96
    /// @param liquidity The liquidity of the pool after the swap
    /// @param tick The log base 1.0001 of price of the pool after the swap
    onSwap(callback) {
        const eventDescription = this.contract.interface.getEvent('Swap');
        const filter = {
            address: this.address,
            // panckaeswap appends two fields to the event, so here is manual signature, not from ABI
            topics: [ Utils.id('Swap(address,address,int256,int256,uint160,uint128,int24,uint128,uint128)') ]
        }
        // websockets connection to logs
        alchemy.ws.on(filter, (log) => {
            const data = this.contract.interface.decodeEventLog(eventDescription, log.data);
            callback({
                amount0: String(data.amount0),
                amount1: String(data.amount1),
                sqrtPriceX96: String(data.sqrtPriceX96),
                liquidity: String(data.liquidity),
                tick: Number(data.tick)
            });
        });
    }

    tickToPrice(tick) {
        return tickToPrice(this.token0, this.token1, Number(tick)).toSignificant(6);
    }
}

module.exports = {
    Pool: Pool
}
