jQuery( function () {
	var i = 1;
	while ( true ) {
		var btn = document.getElementById( 'languageselector-commit-' + i ),
			sel = document.getElementById( 'languageselector-select-' + i ),
			node;

		if ( !btn ) {
			break;
		}

		btn.style.display = 'none';
		sel.onchange = function () {
			node = this.parentNode;
			while ( true ) {
				if ( node.tagName.toLowerCase() === 'form' ) {
					node.submit();
					break;
				}
				node = node.parentNode;
			}
		};

		i++;
	}
} );
