<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';
dd_require_user_login(dd_url('/login/'));
$user = dd_logged_in_user($pdo);
$csrf = dd_csrf_token();
$statement = $pdo->prepare('SELECT ut.id, ut.tracking_id, ut.marketplace, COALESCE(ms.country_label, ut.marketplace) AS country_label, COALESCE(ms.is_active, 0) AS marketplace_active FROM user_tags ut LEFT JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) ORDER BY COALESCE(ms.country_label, ut.marketplace), ut.tracking_id');
$statement->execute([(string) $user['email']]);
$assignedTags = $statement->fetchAll(PDO::FETCH_ASSOC);
$activeTags = array_values(array_filter($assignedTags, static function ($tag) { return (int) $tag['marketplace_active'] === 1; }));
$inactiveTags = array_values(array_filter($assignedTags, static function ($tag) { return (int) $tag['marketplace_active'] !== 1; }));
$activeMarkets = [];
foreach ($activeTags as $tag) $activeMarkets[(string) $tag['marketplace']] = (string) $tag['country_label'];
dd_render_head('Link Generator', ['body_class' => 'storefront-page link-generator-page', 'description' => 'Create Amazon product links using your assigned tracking tag.']);
dd_render_storefront_header('generator', $user);
?>
<main class="link-workspace-main">
  <section class="link-workspace-section"><div class="shell link-workspace-grid">
    <div class="link-workspace-form-card">
      <span class="link-step">PICKWELL · CONTRIBUTOR WORKSPACE</span><h1 class="generator-title">Create your affiliate link</h1><p>Paste an Amazon product URL or product details. We’ll match the marketplace and apply a tracking ID assigned to your account.</p>
      <p class="link-signed-in-as">Signed in as <strong><?= dd_e($user['email']) ?></strong></p>
      <?php if (!$activeTags): ?>
        <?php if ($assignedTags): ?>
          <div class="product-search-notice">Tracking ID(s) are assigned to <strong><?= dd_e($user['email']) ?></strong>, but their marketplace is inactive: <?= dd_e(implode(', ', array_map(static function ($tag) { return $tag['country_label'] . ' · ' . $tag['tracking_id']; }, $inactiveTags))) ?>. Ask the administrator to activate that marketplace in Markets &amp; rates.</div>
        <?php else: ?>
          <div class="product-search-notice">No tracking IDs are assigned to <strong><?= dd_e($user['email']) ?></strong>. Ask the administrator to assign the ID to this exact account email.</div>
        <?php endif; ?>
      <?php else: ?>
      <?php if ($inactiveTags): ?><div class="product-search-notice">Inactive marketplace tags are hidden from link generation: <?= dd_e(implode(', ', array_map(static function ($tag) { return $tag['country_label'] . ' · ' . $tag['tracking_id']; }, $inactiveTags))) ?>.</div><?php endif; ?>
      <form id="linkGeneratorForm" class="link-generator-form">
        <input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>">
        <label><span>Amazon link or product details</span><textarea name="product_message" required maxlength="1200" rows="3" placeholder="Paste one or more Amazon links, ASINs, or product notes"></textarea><small>Paste full or supported short links, ASINs with marketplaces in any order, or a forwarded product message. Multiple products are processed separately.</small></label>
        <label><span>Marketplace tracking ID (optional)</span><select name="tag_id"><option value="">Automatically use my first assigned ID for this marketplace</option><?php foreach ($activeTags as $tag): ?><option value="<?= (int) $tag['id'] ?>" data-marketplace="<?= dd_e($tag['marketplace']) ?>"><?= dd_e($tag['tracking_id']) ?> · <?= dd_e($tag['country_label']) ?></option><?php endforeach; ?></select><small>Choose an assigned ID and marketplace, or let Pickwell select your first assigned ID for the detected marketplace.</small></label>
        <button type="submit" class="link-generator-submit">Generate tracked link <b>↗</b></button>
      </form>
      <div id="linkGeneratorStatus" role="status" aria-live="polite"></div>
      <section class="product-discovery-card" aria-labelledby="productDiscoveryTitle">
        <span class="link-step">PRODUCT DISCOVERY</span><h2 id="productDiscoveryTitle">Search saved and Amazon products</h2>
        <p>Search Pickwell’s catalog first. Seller and store details are shown as unverified when product data does not include them.</p>
        <form id="productDiscoveryForm" class="link-generator-form">
          <input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>">
          <label><span>Keyword (optional)</span><input name="discovery_keyword" type="text" placeholder="e.g. massage gun"></label>
          <label><span>Sold by / seller (optional)</span><input name="discovery_seller" type="text" placeholder="e.g. medisana HK"></label>
          <label><span>Store name (optional)</span><input name="discovery_store" type="text" placeholder="e.g. AnkerDirect"></label>
          <label><span>Brand (optional)</span><input name="discovery_brand" type="text" placeholder="e.g. TEPOTZ"></label>
          <label><span>Product code (optional)</span><input name="discovery_product_code" type="text" placeholder="Model or part number"></label>
          <label><span>Amazon marketplace</span><select name="discovery_marketplace" required><option value="">Choose marketplace</option><?php foreach ($activeMarkets as $market => $country): ?><option value="<?= dd_e($market) ?>"><?= dd_e($country) ?> · <?= dd_e($market) ?></option><?php endforeach; ?></select></label>
          <button type="submit" class="link-generator-submit">Find products <b>↗</b></button>
        </form>
        <div id="productDiscoveryStatus" role="status" aria-live="polite"></div>
      </section>
      <?php endif; ?>
      <div class="link-signed-in-as" style="margin-top:22px">Want to generate product posts from WhatsApp? <a href="<?= dd_e(dd_url('/whatsapp-link/')) ?>"><strong>Connect your WhatsApp number ↗</strong></a></div>
    </div>

  </div></section>
</main>
<script>
(() => {
  const form=document.getElementById('linkGeneratorForm');
  if(!form)return;
  const status=document.getElementById('linkGeneratorStatus');
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    const button=form.querySelector('button');
    button.disabled=true;button.textContent='Generating…';status.replaceChildren();
    try{
      const response=await fetch('<?= dd_e(dd_url('/generate.php')) ?>',{method:'POST',body:new FormData(form),credentials:'same-origin'});
      const data=await response.json();
      if(!response.ok||!data.success)throw new Error(data.message||'Could not generate this link.');
      const urls=Array.isArray(data.post_urls)?data.post_urls:(data.post_url?[data.post_url]:[]);
      if(!urls.length)throw new Error('No product links were generated.');
      const result=document.createElement('div');result.className='link-generator-result product-post-result';
      urls.forEach((url,index)=>{
        const row=document.createElement('div');row.className='product-post-copy-row';
        const productLink=document.createElement('input');productLink.type='url';productLink.readOnly=true;productLink.value=url;productLink.setAttribute('aria-label',`Pickwell product page link ${index+1}`);
        const copy=document.createElement('button');copy.type='button';copy.textContent='Copy link';copy.setAttribute('aria-label',`Copy Pickwell product link ${index+1}`);
        copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(url);copy.textContent='Copied';setTimeout(()=>copy.textContent='Copy link',1600);}catch(_){productLink.focus();productLink.select();copy.textContent='Select and copy';}});
        row.append(productLink,copy);result.append(row);
      });
      status.replaceChildren(result);
      if(Array.isArray(data.errors)&&data.errors.length){const notice=document.createElement('div');notice.className='product-search-notice';notice.textContent=[...new Set(data.errors)].join(' ');status.append(notice);}
    }catch(error){const notice=document.createElement('div');notice.className='product-search-notice';notice.textContent=error.message;status.replaceChildren(notice);}
    finally{button.disabled=false;button.innerHTML='Generate tracked link <b>↗</b>';}
  });
})();
</script>
<script>
(() => {
  const form=document.getElementById('productDiscoveryForm');if(!form)return;
  const status=document.getElementById('productDiscoveryStatus');const generator=document.getElementById('linkGeneratorForm');
  const endpoint='<?= dd_e(dd_url('/generate.php')) ?>';
  function post(data){return fetch(endpoint,{method:'POST',body:data,credentials:'same-origin'}).then(async response=>{const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Product discovery failed.');return result;});}
  function message(text){const notice=document.createElement('div');notice.className='product-search-notice';notice.textContent=text;status.replaceChildren(notice);}
  function showLink(url){const result=document.createElement('div');result.className='link-generator-result product-post-result';const row=document.createElement('div');row.className='product-post-copy-row';const input=document.createElement('input');input.type='url';input.readOnly=true;input.value=url;input.setAttribute('aria-label','Pickwell product page link');const copy=document.createElement('button');copy.type='button';copy.textContent='Copy link';copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(url);copy.textContent='Copied';setTimeout(()=>copy.textContent='Copy link',1600);}catch(_){input.focus();input.select();copy.textContent='Select and copy';}});row.append(input,copy);result.append(row);status.replaceChildren(result);}
  form.addEventListener('submit',async event=>{
    event.preventDefault();const button=form.querySelector('button[type=submit]');button.disabled=true;button.textContent='Searching…';status.replaceChildren();
    try{const data=new FormData(form);data.set('discovery_action','search');const result=await post(data);const list=document.createElement('div');list.className='product-discovery-results';
      result.candidates.forEach((candidate,index)=>{const card=document.createElement('article');card.className='product-discovery-result';if(candidate.image){const image=document.createElement('img');image.src=candidate.image;image.alt='';image.loading='lazy';card.append(image);}const detail=document.createElement('div');const title=document.createElement('h3');title.textContent=candidate.title;detail.append(title);const facts=document.createElement('p');facts.className='product-discovery-facts';facts.textContent='Brand: '+(candidate.brand||'Not available')+(candidate.manufacturer?' · Manufacturer: '+candidate.manufacturer:'')+' · Sold by: '+(candidate.seller_verified?candidate.seller:'Not verified')+' · Store: '+(candidate.store_verified?candidate.store:'Not verified');const meta=document.createElement('p');meta.textContent='ASIN '+candidate.asin+' · Marketplace '+candidate.marketplace+' · Match: '+(candidate.match_explanation||'Marketplace matched');const choose=document.createElement('button');choose.type='button';choose.className='link-generator-submit';choose.textContent='Choose product';choose.addEventListener('click',async()=>{choose.disabled=true;choose.textContent='Creating post…';try{const selection=new FormData();selection.set('csrf_token',form.elements.csrf_token.value);selection.set('discovery_action','select');selection.set('selection_token',result.selection_token);selection.set('selection_number',String(index+1));if(generator)selection.set('tag_id',generator.elements.tag_id.value);const created=await post(selection);if(!created.post_urls||!created.post_urls.length)throw new Error('No product link was created.');showLink(created.post_urls[0]);}catch(error){message(error.message);choose.disabled=false;choose.textContent='Choose product';}});detail.append(facts,meta,choose);card.append(detail);list.append(card);});
      const source=document.createElement('p');source.className='product-discovery-source';source.textContent=result.source==='pickwell_catalog'?'From the Pickwell catalog.':'From the Amazon Creators API.';list.prepend(source);
      if(result.seller_clue_present&&!result.seller_match_found){const sellerNotice=document.createElement('p');sellerNotice.className='product-search-notice';sellerNotice.textContent='No verified seller match was found. Review each candidate’s Sold by information before choosing.';list.prepend(sellerNotice);}
      const reject=document.createElement('button');reject.type='button';reject.className='product-discovery-reject';reject.textContent='None of these — search again';reject.addEventListener('click',async()=>{reject.disabled=true;try{const request=new FormData();request.set('csrf_token',form.elements.csrf_token.value);request.set('discovery_action','reject');request.set('selection_token',result.selection_token);const response=await post(request);message(response.message||'Choices cleared. Refine your search and try again.');form.elements.discovery_keyword.focus();}catch(error){message(error.message);reject.disabled=false;}});list.append(reject);status.replaceChildren(list);
    }catch(error){message(error.message);}finally{button.disabled=false;button.innerHTML='Find products <b>↗</b>';}
  });
})();
</script>
<?php dd_render_footer(); ?>
