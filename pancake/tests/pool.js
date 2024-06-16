const {ethers} = require("ethers");
const { Pool } = require('../src/pool');
const { provider, wallet} = require('../src/wallet');

pool = Pool.factory('0xe37304F7489ed253b2A46A1d9DabDcA3d311D22E').then(pool => {

    pool.withdraw(30895).then(response => {
        response.wait(1).then(receipt => {
            console.log(receipt);
        });
    }).catch(console.error);

    /*pool.stake(30308).then(result => {
        result.wait().then(receipt => {
            console.log(receipt);
        });
    }).catch(console.error);*/

/*    pool.mint(0.000001, 0, -100, 100).then(response => {
        // wait for 1 confirmation
        response.wait(1).then(receipt => {
            const tokenId = Number(receipt.logs[3].topics[3]).toFixed();
            console.log(tokenId);
        })
    }).catch(console.error);*/
});
