const {ethers, provider} = require("../config/ethers");

const PositionManager = new ethers.Contract(
    require('../config/pancake').POSITION_MANAGER_ADDRESS,
    require('@uniswap/v3-periphery/artifacts/contracts/NonfungiblePositionManager.sol/NonfungiblePositionManager.json').abi,
    provider
);

module.exports = {
    PositionManager: PositionManager
}
