import eslint from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'
import tseslint from 'typescript-eslint'
import vueParser from 'vue-eslint-parser'

export default [
  { ignores: ['dist/**', 'coverage/**', '.vite/**'] },
  eslint.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/essential'],
  {
    files: ['*.vue', '**/*.vue'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tseslint.parser,
        sourceType: 'module',
      },
      globals: globals.browser,
    },
    rules: {
      'vue/multi-word-component-names': 'off',
    },
  },
  {
    files: ['*.ts', '**/*.ts'],
    languageOptions: {
      globals: globals.browser,
    },
  },
  {
    rules: {
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
    },
  },
]
