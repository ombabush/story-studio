{{-- The selected object's preview: kind, label and one explicit action. Anchored by the contour or docked. --}}
<div class="ss-ix__preview {{ $anchored ? '' : 'ss-ix__preview--dock' }}" @if($anchored) :style="ixPreviewStyle(ixSel)" @endif role="dialog" :aria-label="ixText(ixItem(ixSel).label)">
    <span class="ss-label" x-text="ixKind(ixItem(ixSel))"></span>
    <b x-text="ixText(ixItem(ixSel).label)"></b>
    <div style="display:flex;gap:6px">
        <button type="button" class="ss-btn ss-btn--primary" @click="ixAct(ixSel, 'cta')" x-text="ixVerb(ixItem(ixSel))"></button>
        <button type="button" class="ss-btn" @click="ixSel = null" :aria-label="t('close')">✕</button>
    </div>
</div>
