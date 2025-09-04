const path = require('path')
const webpack = require('webpack')
const { VueLoaderPlugin } = require('vue-loader')
const MiniCssExtractPlugin = require('mini-css-extract-plugin')

module.exports = {
  mode: 'production',
  entry: {
    familybudget: path.join(__dirname, 'src', 'main.js'),
  },
  output: {
    path: path.resolve(__dirname, 'js'),
    filename: '[name].js',
    publicPath: '/apps/familybudget/js/',
    clean: true,
  },
  resolve: {
    extensions: ['.js', '.vue'],
    alias: {
      'vue$': 'vue/dist/vue.esm.js',
    },
  },
  module: {
    rules: [
      {
        test: /\.vue$/,
        loader: 'vue-loader',
      },
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
        },
      },
      {
        test: /\.css$/i,
        use: [
          MiniCssExtractPlugin.loader,
          'css-loader',
        ],
      },
    ],
  },
  plugins: [
    new VueLoaderPlugin(),
    new webpack.DefinePlugin({
      appName: JSON.stringify('familybudget'),
    }),
    new MiniCssExtractPlugin({
      filename: '../css/[name].css',
    }),
  ],
  devtool: false,
}
