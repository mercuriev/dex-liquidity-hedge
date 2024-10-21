/**
 * Listen NFT Position Manager for events on tracked wallet.
 * Publish on Mint new LP, liquidity change, and Withdraw LP.
 * Only publish, no changes made.
 */
const { PositionManager } = require('./uniswap/PositionManager');
const { ethers} = require('ethers');
const { MasterChef } = require('./config/pancake');
const { provider, wallet } = require('./config/wallet');
const { alchemy } = require('./config/alchemy');
const {Pool} = require("./uniswap/Pool");
const {PoolFactory} = require("./uniswap/PoolFactory");
const {LpToken} = require("./uniswap/LpToken");
const { Pool: V3Pool, Position } = require('@uniswap/v3-sdk');
const sprintf = require('sprintf-js').sprintf;

(async () => {
    /*// query LP tokens events: mint, stake, unstake
    const filter = PositionManager.filters.Transfer(
        [ethers.ZeroAddress, wallet.address, MasterChef.target],
        [wallet.address, MasterChef.target]
    );*/
    // query minted LP tokens
    const filter = PositionManager.filters.Transfer(
        ethers.ZeroAddress, wallet.address
    );

    //const now = await provider.getBlockNumber();
    //const from = now - 12_000_000; // approx 1 month in arbitrum

    let allLogs = await PositionManager.queryFilter(filter, 0, 'latest');
    allLogs = allLogs.reverse();

    for (const e of allLogs) {
        const topics = e.args;
        const from = topics[0];
        const to = topics[1];
        const tokenId = Number(topics[2]);
        const block = await provider.getBlock(e.blockNumber);
        const time = new Date(block.timestamp * 1000).toLocaleString();

        const lpTokens = [];
        if (!lpTokens.hasOwnProperty(tokenId)) { // fetch once
            lpTokens[tokenId] = await LpToken.fetch(tokenId);
        }
        let lpToken = lpTokens[tokenId];

        // active positions
        if (lpToken.liquidity > 0 || true) {
            const pool = await lpToken.getPool();
            const price = await pool.getCurrentPrice();
            const logs = await lpToken.getLogs();

            logs.increase.forEach((log) => {
                console.log(sprintf(
                    '%s#%u (%.2f-%.2f) : %.2f : Open: %.5f %s; %.2f %s',
                    pool.symbol, tokenId,
                    pool.tickToPrice(lpToken.tickLower), pool.tickToPrice(lpToken.tickUpper), price,
                    log.amount0.toSignificant(), log.amount0.currency.symbol,
                    log.amount1.toSignificant(), log.amount1.currency.symbol
                ));
            });

            if (lpToken.liquidity > 0) {
                const liq = new Position({
                    pool: await pool.getState(),
                    liquidity: lpToken.liquidity,
                    tickLower: lpToken.tickLower,
                    tickUpper: lpToken.tickUpper
                });

                const nowToken0 = liq.amount1.toFixed(2) / price;
                const token0delta = nowToken0 - logs.increase[0].amount0.toFixed(5);

                console.log(sprintf(
                    '%s#%u (%.2f-%.2f) : %.2f : Now: %.5f %s; %.2f %s; Result: %.5f %s (%.2f %s)',
                    pool.symbol, tokenId,
                    pool.tickToPrice(lpToken.tickLower), pool.tickToPrice(lpToken.tickUpper), price,
                    liq.amount0.toSignificant(), liq.amount0.currency.symbol,
                    liq.amount1.toSignificant(), liq.amount1.currency.symbol,
                    token0delta, liq.amount0.currency.symbol, token0delta * price, liq.amount1.currency.symbol
                ));
            }

            logs.decrease.forEach((log) => {
                console.log(sprintf(
                    '%s#%u (%.2f-%.2f) : %.2f : Close: %.5f %s; %.2f %s',
                    pool.symbol, tokenId,
                    pool.tickToPrice(lpToken.tickLower), pool.tickToPrice(lpToken.tickUpper), price,
                    log.amount0.toSignificant(), log.amount0.currency.symbol,
                    log.amount1.toSignificant(), log.amount1.currency.symbol
                ));
            });
        }

        // assume all older are withdrawn positions so that we stop processing here
        else {
            break;
        }
    }
})();
