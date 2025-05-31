$( () => {
	let i = 1;
	while ( true ) {
		const btn = document.getElementById( 'languageselector-commit-' + i );
		const sel = document.getElementById( 'languageselector-select-' + i );

		if ( !btn ) {
			break;
		}

		btn.style.display = 'none';
		sel.onchange = function () {
			let node = this.parentNode;
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
