const {Alchemy, Network} = require("alchemy-sdk");

const alchemy = new Alchemy({
    apiKey: '-pIVMYm22LgfrPb32FWlPaKWjXNmH2id',
    network: Network.ARB_MAINNET
});

module.exports = {
    alchemy: alchemy
}
