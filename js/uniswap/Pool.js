const { ethers, provider } = require('../config/ethers');
const { Token, ChainId, JSBI, Percent } =  require('@uniswap/sdk');
const IUniswapV3PoolABI = require('@uniswap/v3-core/artifacts/contracts/interfaces/IUniswapV3Pool.sol/IUniswapV3Pool.json').abi;
const ERC20Abi = require('erc-20-abi');
const { alchemy } = require('../config/alchemy');

class Pool {
    constructor(address) {
        this.address = address;
    }

    static async factory(address) {
        const self = new Pool(address);
        self.contract = new ethers.Contract(address, IUniswapV3PoolABI, provider);
        const buildToken = async function(tokenAddress) {
            const contract = new ethers.Contract(tokenAddress, ERC20Abi, provider);
            const info = await Promise.all([
                contract.decimals(),
                contract.symbol(),
                contract.name()
            ]);
            const token = new Token(ChainId.MAINNET, tokenAddress, Number(info[0]), info[1], info[2]);
            token.contract = contract;
            return token;
        };
        self.token0 = await buildToken(await self.contract.token0());
        self.token1 = await buildToken(await self.contract.token1());
        self.symbol = self.token0.symbol + '-' + self.token1.symbol;
        self.fee    = Number(await self.contract.fee());
        return self;
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
        await this.approve(nftManager.target);

        // fetch current pool state (price and tick)
        const [liquidity, slot0, ticks] = await Promise.all([
            this.contract.liquidity(),
            this.contract.slot0(),
            this.contract.ticks(-10000)
        ]);

        const poolState = new UniswapPool(
            this.token0,
            this.token1,
            this.fee,
            slot0.sqrtPriceX96.toString(),
            liquidity.toString(),
            Number(slot0.tick)
        );

        const [tickLower, tickHigher] = this.findTicksForPrices(poolState, slot0, low, high);

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

        const { calldata, value } = NonfungiblePositionManager.addCallParameters(position, mintOptions);
        const transaction = {
            to: nftManager.target,
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
            recipient: masterChef.target,
            tokenId: tokenId
        });
        const tx = {
            to: nftManager.target,
            from: wallet.address,
            data: params.calldata,
            value: params.value
        }
        return wallet.sendTransaction(tx);
    }

    async withdraw(tokenId)
    {
        const position = await nftManager.positions(tokenId);
        if (position[7] === 0n) throw new Error('Position already withdrawn');

        let calls = []; // it's a multicall
        calls.push(await masterChef.getFunction('decreaseLiquidity').populateTransaction([
            tokenId,
            position[7],
            position[10],
            position[11],
            Math.floor(Date.now() / 1000) + 60 * 20
        ]));
        calls.push(await masterChef.getFunction('collect').populateTransaction([
            tokenId,
            wallet.address,
            BigInt(2 ** 127),
            BigInt(2 ** 127)
        ]));

        // if NOT STAKED then call ntfManager, if staked masterChef
        const owner = await nftManager.ownerOf(tokenId);
        if (owner === masterChef.target) {
            return masterChef.multicall(calls.map(call => call.data));
        } else {
            return nftManager.multicall(calls.map(call => call.data));
        }
    }

    /**
     * Return ticks that are usable for minting a position with the given prices.
     * The prices are given as float token1 / token0 (reverse!)
     *
     * @param poolContract
     * @param slot0
     * @param float0
     * @param float1
     */
    findTicksForPrices(poolContract, slot0, float0, float1)
    {
        // hardcode the range of ticks to search for the given prices for efficiency
        const lowestTick = Number(slot0.tick) - 10000 * poolContract.tickSpacing;
        const highestTick = Number(slot0.tick) + 10000 * poolContract.tickSpacing;
        let lowTick = null;
        let highTick = null;
        for (let tick = lowestTick; tick < highestTick; tick += poolContract.tickSpacing) {
            const price = tickToPrice(this.token0, this.token1, tick).toSignificant();
            if (lowTick === null && price > float0) {
                lowTick = tick;
                if (Number(float1) === 0) {
                    lowTick += poolContract.tickSpacing;
                }
            }
            if (highTick === null && price > float1) {
                highTick = tick;
                if (Number(float0) === 0) {
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

    onSwap(callback) {
        const filter = {
            address: this.address,
            topics: [
                '0x19b47279256b2a23a1665c810c8d55a1758940ee09377d4f8d26497a3577dc83' // swap event
            ]
        }
        // websockets connection to logs
        alchemy.ws.on(filter, (log) => {
            // Decode the event data
            const eventDescription = this.contract.interface.getEvent('Swap');
            const decodedData = this.contract.interface.decodeEventLog(eventDescription, log.data);

            callback(decodedData);
        });
    }
}

module.exports = {
    Pool: Pool
}
