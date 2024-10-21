const {ethers, provider} = require("../config/ethers");
const {Token, ChainId} = require("@uniswap/sdk");
const ERC20Abi = require('erc-20-abi');

async function buildToken(tokenAddress) {
    const contract = new ethers.Contract(tokenAddress, ERC20Abi, provider);
    const info = await Promise.all([
        contract.decimals(),
        contract.symbol(),
        contract.name()
    ]);
    const token = new Token(ChainId.MAINNET, tokenAddress, Number(info[0]), info[1], info[2]);
    token.contract = contract;
    return token;
}

module.exports = {
    buildToken
}
