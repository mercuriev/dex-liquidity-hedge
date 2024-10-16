const amqp = require('amqplib')
const { Pool } = require('./uniswap/Pool');
const { Pool: V3Pool, Position } = require('@uniswap/v3-sdk');
const { PositionManager } = require('./uniswap/PositionManager');
const { PoolFactory } = require('./uniswap/PoolFactory');
const sprintf = require('sprintf-js').sprintf;

async function watch(tokenId, ch) {
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

        ch.publish('lp', pool.symbol, Buffer.from(JSON.stringify({
            pool: pool.symbol,
            tokenId: tokenId,
            low: pool.tickToPrice(LP[5]),
            high: pool.tickToPrice(LP[6]),
            price: price,
            amount0: position.amount0.toFixed(4),
            amount1: position.amount1.toFixed(2)
        })));
    });
}

(async () =>
{
    // if connection fails all below is useless
    let ch = await amqp.connect('amqp://rabbitmq');
    ch = await ch.createChannel();
    ch.assertExchange('lp', 'topic', {durable: true});

    const q = await ch.assertQueue('liquidity', {exclusive: true});
    ch.bindQueue(q.queue, 'lp', 'start.*');
    ch.bindQueue(q.queue, 'lp', 'stop.*');

    ch.consume(q.queue, async (msg) => {
        const tokenId = msg.fields.routingKey.split('.')[1];
        await watch(tokenId, ch);
        ch.ack(msg);
    });
})();
