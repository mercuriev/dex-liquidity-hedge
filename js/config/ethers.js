const { ethers } = require('ethers');
const provider = new ethers.JsonRpcProvider('https://arb1.arbitrum.io/rpc');

module.exports = {
    ethers: ethers,
    provider: provider
}
