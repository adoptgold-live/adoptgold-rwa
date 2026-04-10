<?php
// /rwa/login/telegram.php
// Telegram PIN login (standalone RWA)

require __DIR__.'/../inc/rwa-session.php';
require __DIR__.'/../../dashboard/inc/session-user.php';
require __DIR__.'/../../dashboard/inc/gt.php';

if(!empty($_SESSION['wallet'])){
header("Location: /rwa/login-select.php");
exit;
}

$bot="adoptgold_bot";
?>

<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Telegram Login</title>

<style>

body{
margin:0;
background:#0b0716;
font-family:system-ui;
color:#fff;
display:flex;
justify-content:center;
align-items:center;
height:100vh;
}

.card{
width:420px;
max-width:92%;
background:#16112a;
border-radius:16px;
padding:22px;
box-shadow:0 0 30px rgba(150,100,255,.25);
}

h2{
margin-top:0;
}

.chain{
float:right;
font-size:12px;
background:#1f1a3a;
padding:4px 8px;
border-radius:20px;
}

input{
width:100%;
padding:12px;
border-radius:10px;
border:1px solid rgba(255,255,255,.2);
background:#1c1733;
color:#fff;
margin-top:12px;
font-size:16px;
}

button{
width:100%;
padding:12px;
margin-top:12px;
border:none;
border-radius:10px;
font-weight:600;
cursor:pointer;
}

.bot{
background:#2AABEE;
}

.verify{
background:#7c5cff;
}

.secondary{
background:#444;
}

</style>

</head>

<body>

<div class="card">

<div class="chain">● Chain ID: 7709304653</div>

<h2>Telegram PIN</h2>

<p>Open bot → generate PIN → verify</p>

<button class="bot" onclick="openBot()">
Generate PIN with Bot
</button>

<input id="pin" placeholder="Enter 6-digit PIN">

<button class="verify" onclick="verifyLogin()">
Verify & Login
</button>

<button class="secondary" onclick="location.href='/rwa/index.php'">
Back
</button>

<button class="secondary" onclick="location.href='/rwa/logout.php'">
Logout
</button>

</div>

<script>

function openBot(){
window.open("https://t.me/<?php echo $bot;?>","_blank");
}

async function verifyLogin(){

let pin=document.getElementById("pin").value.trim();

if(!/^[0-9]{6}$/.test(pin)){
alert("PIN must be 6 digits");
return;
}

let res=await fetch("/rwa/auth/tg/login.php",{
method:"POST",
headers:{
"Content-Type":"application/json"
},
body:JSON.stringify({pin:pin})
});

let data=await res.json();

if(data.ok){

window.location=data.redirect || "/rwa/login-select.php";

}else{

alert(data.error || "Login failed");

}

}

</script>

<script src="/dashboard/inc/poado-i18n.js"></script>

</body>
</html>