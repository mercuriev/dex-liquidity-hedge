const fs = require('fs');
const { ethers, provider } = require('./ethers');

let pkey;
if (process.env.WALLET) {
    pkey = process.env.WALLET;
} else {
    pkey = fs.readFileSync('/run/secrets/wallet', 'utf8');
}

if (pkey) {
    module.exports = {
        provider: provider,
        wallet: new ethers.Wallet(pkey.trim(), provider)
    }
}
else throw new Error('Private key not found in environment variable or "wallet" docker secret.');
