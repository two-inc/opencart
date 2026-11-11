/** Jest over the extension's browser scripts, in jsdom. */
const path = require('path');

module.exports = {
    rootDir: path.resolve(__dirname, '../..'),
    testEnvironment: 'jsdom',
    testMatch: ['<rootDir>/tests/js/**/*.test.js'],
    restoreMocks: true,
    resetMocks: true
};
