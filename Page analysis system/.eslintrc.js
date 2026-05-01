module.exports = {
    env: {
        browser: true,
        es2021: true,
        node: true,
    },
    extends: [
        'eslint:recommended',
    ],
    parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'script',
    },
    globals: {
        // Loaded via CDN <script> tag at runtime
        html2pdf: 'readonly',
    },
    rules: {
        // Indentation/quoting in this codebase is intentionally mixed (Arabic
        // comments, generated HTML in template literals, RTL-aware formatting).
        // Enforcing a single style would balloon every diff. Disable the noisy
        // style rules; keep correctness rules (recommended set + parse errors).
        'indent': 'off',
        'quotes': 'off',
        'linebreak-style': ['error', 'unix'],
        'semi': ['error', 'always'],
        'no-unused-vars': 'warn',
        'no-useless-escape': 'warn',
        'no-console': 'off',
    },
    ignorePatterns: [
        'node_modules/',
        'cache/',
        'logs/',
        'backup_temp/',
        '*.min.js',
    ],
};
