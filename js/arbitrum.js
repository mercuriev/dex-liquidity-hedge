const amqp = require('amqplib')
const util = require("node:util");
const { Pool } = require('./uniswap/Pool');

async function mint(msg, ch){
    const {
        pool: poolAddress,
        amount0,
        amount1,
        low,
        high
    } = JSON.parse(msg.content.toString());

    const pool = await Pool.factory(poolAddress);
    console.info(util.format('Pool %s: adding %s %s and %s %s', pool.symbol, amount0, pool.token0.symbol, amount1, pool.token1.symbol));

    const response = await pool.mint(amount0, amount1, low, high);
    const receipt = await response.wait(1); // wait for 1 block

    const tokenId = Number(receipt.logs[3].topics[3]).toFixed();
    console.info(util.format('Pool %s: minted token %s', pool.symbol, tokenId));

    ch.publish('', msg.properties.replyTo, Buffer.from(JSON.stringify({
        pool: poolAddress,
        tokenId: tokenId,
        receipt: receipt
    })));
    ch.ack(msg);
}

async function stake(msg, ch){
    const { tokenId } = JSON.parse(msg.content.toString());

    console.info('Staking token to farm: ' + tokenId);
    const pool = new Pool; // not required to fetch pool data
    const response = await pool.stake(tokenId);
    const receipt = await response.wait(1);
    console.info(util.format('Staked token %s', tokenId));
    ch.publish('', msg.properties.replyTo, Buffer.from(JSON.stringify(receipt)));
    ch.ack(msg);
}

async function withdraw(msg, ch){

}

amqp.connect('amqp://rabbitmq').then(async (conn) => {
    const ch = await conn.createChannel();

    await ch.assertExchange('arbitrum', 'topic', { durable: true });

    await ch.assertQueue('arbitrum.mint', { exclusive: true });
    ch.bindQueue('arbitrum.mint', 'arbitrum', 'mint');
    ch.consume('arbitrum.mint', (msg) => mint(msg, ch));

    await ch.assertQueue('arbitrum.stake', { exclusive: true });
    ch.bindQueue('arbitrum.stake', 'arbitrum', 'stake');
    ch.consume('arbitrum.stake', (msg) => stake(msg, ch));

    await ch.assertQueue('arbitrum.withdraw', { exclusive: true });
    ch.bindQueue('arbitrum.withdraw', 'arbitrum', 'withdraw');
    ch.consume('arbitrum.withdraw', (msg) => withdraw(msg, ch));
});
