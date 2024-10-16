const { FACTORY_ADDRESS } = require('../config/pancake');
const { ethers, provider } = require('../config/ethers');

const PoolFactory = new ethers.Contract(
    FACTORY_ADDRESS,
    require('@uniswap/v3-core/artifacts/contracts/interfaces/IUniswapV3Factory.sol/IUniswapV3Factory.json').abi,
    provider
);

module.exports = {
    PoolFactory: PoolFactory
}
