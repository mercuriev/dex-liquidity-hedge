const {ethers, provider } = require("./ethers");
const { wallet} = require('./wallet');
module.exports = {
    FACTORY_ADDRESS: '0x0BFbCF9fa4f9C56B0F40a671Ad40E0805A091865',
    POSITION_MANAGER_ADDRESS: '0x46A15B0b27311cedF172AB29E4f4766fbE7F4364',
    masterChef: new ethers.Contract(
        '0x5e09ACf80C0296740eC5d6F643005a4ef8DaA694',
        require('../abi/masterchef.abi.json'),
        wallet
    )
}
