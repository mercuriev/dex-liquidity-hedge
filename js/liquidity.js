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
    const position = await PositionManager.positions(tokenId);
    const pool = await Pool.factory(await PoolFactory.getPool(
        position[2],    // token0
        position[3],    // token1
        position[4]     // fee
    ));
    console.info(sprintf(
        //'%s#%u : %.2f - %.2f : %.6f %s / %.2f %s',
        '%s LP#%u : %.2f - %.2f',
        pool.symbol, tokenId,
        pool.tickToPrice(position[5]), pool.tickToPrice(position[6]),
    ));

    // Track position liquidity each time a swap occurs
    let lastPrice = null;
    pool.onSwap((swap) => {
        const poolState = new V3Pool(pool.token0, pool.token1, pool.fee, swap.sqrtPriceX96, swap.liquidity, swap.tick);
        const price = poolState.token0Price.toSignificant(6);

        if (lastPrice === price) return;
        lastPrice = price;

        console.log(pool.symbol, 'price:', price);

        PositionManager.positions(tokenId).then(res => {
            const pos = new Position({
                pool: poolState,
                liquidity: String(res[7]),
                tickLower: Number(res[5]),
                tickUpper: Number(res[6])}
            );
            // 4 digits is the binance LOT_SIZE
            console.log(sprintf(
                'LP#%u : %.4f %s / %.2f %s',
                tokenId,
                pos.amount0.toFixed(4), pos.amount0.currency.symbol,
                pos.amount1.toFixed(2), pos.amount1.currency.symbol
            ));
        });
    });
})();
