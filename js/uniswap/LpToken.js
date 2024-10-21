const {Pool} = require("./Pool");
const {PoolFactory} = require("./PoolFactory");
const {PositionManager} = require("./PositionManager");
const {Position} = require("@uniswap/v3-sdk");
const {buildToken} = require("./Token");
const { CurrencyAmount } = require('@uniswap/sdk-core')

class LpToken {
    constructor(id) {
        this.id = id;
        this.pool = null;
    }

    static async fetch(id) {
        const self = new LpToken(id);
        const res = await PositionManager.positions(id);
        self.operator = res[1];
        self.token0 = await buildToken(res[2]);
        self.token1 = await buildToken(res[3]);
        self.fee = res[4];
        self.tickLower = Number(res[5]);
        self.tickUpper = Number(res[6]);
        self.liquidity = String(res[7]);
        self.feeGrowthInside0LastX128 = String(res[8]);
        self.feeGrowthInside1LastX128 = String(res[9]);
        self.tokensOwed0 = String(res[10]);
        self.tokensOwed1 = String(res[11]);

        return self;
    }

    /**
     * Find pool for tokens pair in the given LP.
     *
     * @returns Pool
     */
    async getPool()
    {
        if (this.pool) return this.pool;

        const poolAddress = await PoolFactory.getPool(this.token0.address, this.token1.address, this.fee);
        return this.pool = await Pool.factory(poolAddress);
    }

    async getState()
    {
        await this.getPool();
        const poolState = await this.pool.getState();

        return this.state = new Position({
            pool: poolState,
            liquidity: this.liquidity,
            tickLower: this.tickLower,
            tickUpper: this.tickUpper
        });
    }

    async getLogs() {
        let logs = {
            increaseLiquidity: PositionManager.filters.IncreaseLiquidity([this.id]),
            decreaseLiquidity: PositionManager.filters.DecreaseLiquidity([this.id]),
            //collect: PositionManager.filters.Collect([this.id]),
        }

        return Promise.all([
            PositionManager.queryFilter(logs.increaseLiquidity, 0, 'latest'),
            PositionManager.queryFilter(logs.decreaseLiquidity, 0, 'latest'),
            //PositionManager.queryFilter(logs.collect, 0, 'latest')
        ]).then(([increase, decrease, collect]) => {
            return {
                increase: increase.map((log) => {
                    return {
                        block: log.blockNumber,
                        amount0: CurrencyAmount.fromRawAmount(this.token0, String(increase[0].args[2])),
                        amount1: CurrencyAmount.fromRawAmount(this.token1, String(increase[0].args[3])),
                    }
                }),
                decrease: decrease.map((log) => {
                    return {
                        block: log.blockNumber,
                        amount0: CurrencyAmount.fromRawAmount(this.token0, String(decrease[0].args[2])),
                        amount1: CurrencyAmount.fromRawAmount(this.token1, String(decrease[0].args[3])),
                    }
                }),
                //collect: {}
            };
        });
    }
}

module.exports = { LpToken };
