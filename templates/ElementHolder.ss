<div class="element $SimpleClassName.LowerCase<% if $StyleVariant %> $StyleVariant<% end_if %><% if $ExtraClass %> $ExtraClass<% end_if %>" id="$Anchor">
	<% uncached %>
		<% if canEdit && isCMSPreview == 0 %>
			<div style="position: relative">
				<a href="$CMSEditLink" style="position: absolute; right: 0; top: 0; padding: 5px 10px; background: #0071c4; color: white;">Edit</a>
			</div>
		<% end_if %>
	<% end_uncached %>
	<% if InContainer %><div class="container"><% end_if %>
	$Element
	<% if InContainer %></div><% end_if %>
</div>
