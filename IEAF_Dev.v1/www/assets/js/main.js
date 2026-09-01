document.querySelectorAll(".alert--success").forEach(el=>{setTimeout(()=>{el.style.transition="opacity .5s";el.style.opacity="0";setTimeout(()=>el.remove(),500)},5000)});
document.querySelectorAll("[data-confirm]").forEach(el=>{el.addEventListener("click",e=>{if(!confirm(el.dataset.confirm))e.preventDefault()})});
