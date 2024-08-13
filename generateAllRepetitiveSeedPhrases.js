// Import the bip39 package
const bip39 = require('bip39');

// Get the BIP39 wordlist
const wordlist = bip39.wordlists.english;

// Function to repeat a word n times
function repeatWord(word, times) {
    return Array(times).fill(word).join(' ');
}

// Iterate through each word in the wordlist
wordlist.forEach(word => {
    let mnemonic = repeatWord(word, 12);

    if (bip39.validateMnemonic(mnemonic)) {
        console.log(mnemonic);
    }
});

wordlist.forEach(word => {
    let mnemonic = repeatWord(word, 24);

    if (bip39.validateMnemonic(mnemonic)) {
        console.log(mnemonic);
    }
});