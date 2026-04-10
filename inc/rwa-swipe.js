let touchStartX=0;
let touchEndX=0;

const pages=[
"/rwa/rlife/index.php",
"/rwa/mining/index.php",
"/rwa/wallet/index.php",
"/rwa/vault/index.php",
"/rwa/profile/index.php"
];

function currentIndex(){

let path=window.location.pathname;

for(let i=0;i<pages.length;i++){

if(path.includes(pages[i])) return i;

}

return 0;

}

document.addEventListener("touchstart",(e)=>{

touchStartX=e.changedTouches[0].screenX;

});

document.addEventListener("touchend",(e)=>{

touchEndX=e.changedTouches[0].screenX;

handleSwipe();

});

function handleSwipe(){

let diff=touchStartX-touchEndX;

let index=currentIndex();

if(Math.abs(diff)<80) return;

if(diff>0){

if(index<pages.length-1){

window.location.href=pages[index+1];

}

}else{

if(index>0){

window.location.href=pages[index-1];

}

}

}