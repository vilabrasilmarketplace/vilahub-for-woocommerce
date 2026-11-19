/* Restriction preview only; publication remains validated by the server. */
document.addEventListener('DOMContentLoaded', async () => {
    const cells = Array.from(document.querySelectorAll('.vilahub-restrictions[data-product-id]'));
    if (!cells.length || typeof vilahubRestrictions === 'undefined') return;
    for (let offset=0; offset<cells.length; offset+=50) {
        const batch=cells.slice(offset,offset+50);
        batch.forEach(cell => { cell.textContent='Verificando restrições…'; });
        try {
            const body=new URLSearchParams({action:'vilahub_restrictions', _ajax_nonce:vilahubRestrictions.nonce});
            batch.forEach(cell => body.append('ids[]',cell.dataset.productId));
            const response=await fetch(vilahubRestrictions.url,{method:'POST',credentials:'same-origin',body});
            const result=await response.json();
            if (!response.ok || !result.success || !Array.isArray(result.data)) throw new Error('unavailable');
            batch.forEach(cell => {
                const product=result.data.find(item => item.id===cell.dataset.productId);
                if (!product || !Array.isArray(product.issues)) throw new Error('incomplete');
                cell.replaceChildren();
                if (!product.issues.length) return;
                const prohibited=product.issues.filter(issue => issue.code==='PROHIBITED_TERM'||/^Palavra proibida\b/i.test(String(issue.message||'')));
                if (prohibited.length) {
                    const words=[...new Set(prohibited.map(issue => {
                        if (issue.matchedKeyword) return String(issue.matchedKeyword).trim();
                        const match=String(issue.message||'').match(/[\u201c"]([^\u201d"]+)[\u201d"]/);
                        return match ? match[1].trim() : '';
                    }).filter(Boolean))];
                    const box=document.createElement('div');
                    box.className='vilahub-restriction '+(prohibited.some(issue => issue.severity==='BLOCK')?'is-blocked':'is-warning');
                    box.textContent=words.length?'Palavra proibida '+words.map(word=>'“'+word+'”').join(', '):'Palavra proibida';
                    cell.append(box);
                }
                product.issues.filter(issue => !prohibited.includes(issue)).forEach(issue => {
                    const box=document.createElement('div'); box.className='vilahub-restriction '+(issue.severity==='BLOCK'?'is-blocked':'is-warning');
                    box.textContent=String(issue.message||'Ajuste necessário.');
                    cell.append(box);
                });
            });
        } catch (_) { batch.forEach(cell => { cell.textContent='Não verificado. Atualize a página para tentar novamente.'; }); }
    }
});
