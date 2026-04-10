function rwaToast(title,msg){

let container=document.getElementById("rwaToastContainer");

if(!container){

container=document.createElement("div");

container.id="rwaToastContainer";

container.className="rwa-toast-container";

document.body.appendChild(container);

}

let toast=document.createElement("div");

toast.className="rwa-toast";

toast.innerHTML="<b>"+title+"</b><br>"+msg;

container.appendChild(toast);

setTimeout(()=>{

toast.remove();

},4000);

}