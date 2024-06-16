const amqp = require('amqplib/callback_api');
const { Pool } = require('./pool');
const util = require("node:util");
const exchange = 'pool';

/**
 * Queue is registered but not yet consuming. Bind queue and consume messages.
 * @param channel
 */
function defineQueuesAndConsume(channel)
{
    const onError = function(e, msg, channel) {
        console.error(e);
        channel.nack(msg, false, false);
    }

    channel.assertQueue('pool.mint', { exclusive: true }, function(error, q) {
        if (error) throw error;
        channel.bindQueue(q.queue, exchange, '*.mint'); // pool_address.mint
        console.debug(util.format('%s: consuming %s with rkey %s', q.queue, exchange, '*.mint'));
        channel.consume(q.queue, function(msg) {
            let address = msg.fields.routingKey.split('.')[0];
            let params = JSON.parse(msg.content.toString());

            console.info('Fetching pool ' + address);
            Pool.factory(address).then(pool => {
                console.info(util.format('Pool %s: adding %s %s and %s %s', pool.symbol, params.amount0, pool.token0.symbol, params.amount1, pool.token1.symbol));
                pool.mint(params.amount0, params.amount1, params.low, params.high).then(response => {
                    response.wait(1).then(receipt => {
                        const tokenId = Number(receipt.logs[3].topics[3]).toFixed();
                        console.info(util.format('Pool %s: minted token %s', pool.symbol, tokenId));
                        channel.publish('', msg.properties.replyTo, Buffer.from(JSON.stringify({
                            pool: address,
                            tokenId: tokenId,
                            receipt: receipt
                        })));
                        channel.ack(msg);
                    }).catch(e => onError(e, msg, channel));
                }).catch(e => onError(e, msg, channel));
            }).catch(e => onError(e, msg, channel));
        });
    });

    channel.assertQueue('stake', { exclusive: true }, function(error, q) {
        if (error) throw error;
        channel.bindQueue(q.queue, exchange, '*.stake'); // tokenId.stake
        console.debug(util.format('%s: consuming %s with rkey %s', q.queue, exchange, '*.stake'));
        channel.consume(q.queue, function(msg) {
            let tokenId = msg.fields.routingKey.split('.')[0];

            console.info('Staking token to farm: ' + tokenId);
            const pool = new Pool; // not required to fetch pool data
            pool.stake(tokenId).then(response => {
                response.wait(1).then(receipt => {
                    console.info(util.format('Staked token %s', tokenId));
                    channel.publish('', msg.properties.replyTo, Buffer.from(JSON.stringify(receipt)));
                    channel.ack(msg);
                }).catch(e => onError(e, msg, channel));
            }).catch(e => onError(e, msg, channel));
        });
    });

    channel.assertQueue('withdraw', { exclusive: true }, function(error, q) {
        if (error) throw error;
        channel.bindQueue(q.queue, exchange, '*.withdraw'); // tokenId.withdraw
        console.debug(util.format('%s: consuming %s with rkey %s', q.queue, exchange, '*.withdraw'));
        channel.consume(q.queue, function(msg) {
            let tokenId = msg.fields.routingKey.split('.')[0];

            console.info('Withdrawing liquidity: ' + tokenId);
            const pool = new Pool; // not required to fetch pool data
            pool.withdraw(tokenId).then(response => {
                response.wait(1).then(receipt => {
                    console.info(util.format('Withdrew liquidity from token %s', tokenId));
                    channel.publish('', msg.properties.replyTo, Buffer.from(JSON.stringify(receipt)));
                    channel.ack(msg);
                }).catch(e => onError(e, msg, channel));
            }).catch(e => onError(e, msg, channel));
        });
    });

        /*
        pair.observePrice(function(price, tick, blockNumber) {
            let content = {
                block: blockNumber,
                pair: pair.symbol,
                token0: {
                    id: pair.token0.address,
                    name: pair.token0.name,
                    symbol: pair.token0.symbol,
                    decimals: pair.token0.decimals,
                },
                token1: {
                    id: pair.token1.address,
                    name: pair.token1.name,
                    symbol: pair.token1.symbol,
                    decimals: pair.token1.decimals,
                },
                feeTier: pair.fee,
                tick: tick,
                token0Price: price.toSignificant(),
                token1Price: price.invert().toSignificant(),
            }
            channel.publish(exchange, 'price.'+pair.symbol, Buffer.from(JSON.stringify(content)));
        });*/
}

/**
 * Register exchange and queue and execute the run function.
 */
amqp.connect('amqp://rabbitmq', function(error, connection) {
    if (error) throw error;
    connection.createChannel(function(error, channel) {
        if (error) throw error;
        channel.assertExchange(exchange, 'topic', { durable: true });
        defineQueuesAndConsume(channel);
    });
});
