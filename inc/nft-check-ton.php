<?php

function rwa_user_has_adoptgold_nft($wallet){

if(!$wallet) return false;

$url="https://tonapi.io/v2/accounts/".$wallet."/nfts";

$ctx=stream_context_create([
'http'=>[
'timeout'=>3
]
]);

$json=@file_get_contents($url,false,$ctx);
if(!$json) return false;

$data=json_decode($json,true);

if(empty($data['nft_items'])) return false;

foreach($data['nft_items'] as $n){

if(strpos($n['collection']['name'] ?? '', 'AdoptGold')!==false){
return true;
}

}

return false;

}