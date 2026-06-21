#!/bin/bash

source ~/.nvm/nvm.sh
nvm use node

echo '------------------------------'
echo ' run build'
echo '------------------------------'
cd themes/sswebpack_engine_only/
npm install
npm run build --theme_dir=vendor/sunnysideup/elementalarea-check/client --include_jquery=no
echo '------------------------------'
