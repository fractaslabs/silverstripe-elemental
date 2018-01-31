<div class="elemental-preview">
    <a href="$CMSEditLink" class="elemental-edit">
        <div class="elemental-preview__icon">$Icon</div>
        <div class="elemental-preview__detail">
            <h3><% if Title %>$Title <% end_if %><small>$Type</small>
                <span class="elemental-preview__status-flag {$StatusFlag.ClassName}" title="{$StatusFlag.Description}">$StatusFlag.Title</span>
            </h3>
            <% if $Summary %>
                <p>$Summary.RAW</p>
            <% end_if %>
        </div>
    </a>
</div>
