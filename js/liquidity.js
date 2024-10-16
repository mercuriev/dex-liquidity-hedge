const { Pool } = require('./uniswap/Pool');
const { Pool: V3Pool, Position } = require('@uniswap/v3-sdk');
const { PositionManager } = require('./uniswap/PositionManager');
const { PoolFactory } = require('./uniswap/PoolFactory');
const sprintf = require('sprintf-js').sprintf;

// TODO - get token id from user input
const tokenId = 134574;

(async () =>
{
    // Initial fetch of pool address for given LP token so that we listen on this pool
    const LP = await PositionManager.positions(tokenId);
    const pool = await Pool.factory(await PoolFactory.getPool(
        LP[2],    // token0
        LP[3],    // token1
        LP[4]     // fee
    ));

    // Track position liquidity each time a swap occurs
    let lastPrice = null;
    pool.onSwap((swap) =>
    {
        const poolState = new V3Pool(pool.token0, pool.token1, pool.fee, swap.sqrtPriceX96, swap.liquidity, swap.tick);
        const price = poolState.token0Price.toFixed(2);

        if (lastPrice === price) return;
        lastPrice = price;

        const position = new Position({
            pool: poolState,
            liquidity: String(LP[7]),
            tickLower: Number(LP[5]),
            tickUpper: Number(LP[6])}
        );
        // 4 digits is the binance LOT_SIZE
        console.log(sprintf(
            '%s#%u (%.2f-%.2f) : %.2f : %.4f %s / %.2f %s',
            pool.symbol, tokenId,
            pool.tickToPrice(LP[5]), pool.tickToPrice(LP[6]), price,
            position.amount0.toFixed(4), position.amount0.currency.symbol,
            position.amount1.toFixed(2), position.amount1.currency.symbol
        ));
    });
})();
