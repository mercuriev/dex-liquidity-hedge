const { ethers } = require('ethers');
const { Pool } = require('./uniswap/Pool');
const { Pool: V3Pool, Position, tickToPrice } = require('@uniswap/v3-sdk');

const nftManager = new ethers.Contract(
    '0x46A15B0b27311cedF172AB29E4f4766fbE7F4364',
    require('@uniswap/v3-periphery/artifacts/contracts/NonfungiblePositionManager.sol/NonfungiblePositionManager.json').abi,
    new ethers.JsonRpcProvider('https://arb1.arbitrum.io/rpc')
);

Pool.factory('0x389938cf14be379217570d8e4619e51fbdafaa21').then(pool =>
{
    console.log('Listening for swaps on pool: ', pool.symbol);

    pool.onSwap((event) => {
        console.log('amount0: ', event[2]);
        console.log('amount1: ', event[3]);
        console.log('sqrtPriceX96: ', event[4]);
        console.log('liquidity: ', event[5]);
        console.log('tick: ', event[6]);
        console.log('tickToPrice: ', tickToPrice(pool.token0, pool.token1, Number(event[6])).toSignificant(6));

        const p = new V3Pool(pool.token0, pool.token1, pool.fee, String(event[4]), String(event[5]), Number(event[6]));
        //console.log(p);
        console.log('Token0 price: ', p.token0Price.toSignificant(6));

        nftManager.positions(134574).then(res => {
            const position = new Position({
                pool: p,
                liquidity: String(res[7]),
                tickLower: Number(res[5]),
                tickUpper: Number(res[6])}
            );
            console.log(position.amount0.currency.symbol + ': ', position.amount0.toSignificant(6));
            console.log(position.amount1.currency.symbol + ': ', position.amount1.toSignificant(6));
        });

        console.log('---');
    })
});
