// Affiche la carte de la page une fois le service « openstreetmap » accepté dans tarteaucitron.
(function () {
	'use strict';
	var lancee = false;

	function icone(adresse, reglage) {
		return L.icon({ iconUrl: adresse, iconAnchor: [reglage.x, reglage.y], popupAnchor: [0, -reglage.y] });
	}

	function lancer() {
		var conteneur = document.getElementById('carte');
		if (lancee || !conteneur) {
			return;
		}
		lancee = true;
		var donnees = JSON.parse(document.getElementById('carte-donnees').textContent);
		conteneur.textContent = '';
		var carte = L.map(conteneur);
		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			// Le serveur de tuiles exige le Referer : seule l'origine du site lui est donnée.
			referrerPolicy: 'strict-origin-when-cross-origin',
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
		}).addTo(carte);

		var groupe = donnees.degroupage === null
			? L.featureGroup()
			: L.markerClusterGroup({ disableClusteringAtZoom: donnees.degroupage });
		donnees.marqueurs.forEach(function (m) {
			var options = { title: m.titre, draggable: donnees.deplacable };
			var adresse = m.icone || (donnees.icone && donnees.icone.adresse);
			if (adresse) {
				options.icon = icone(adresse, donnees.icone);
			}
			var marqueur = L.marker([m.lat, m.lon], options);
			if (m.bulle) {
				marqueur.bindPopup(m.bulle);
			}
			if (donnees.deplacable) {
				marqueur.on('dragend', function () {
					var position = marqueur.getLatLng();
					document.getElementById('lon').value = position.lng;
					document.getElementById('lat').value = position.lat;
				});
				document.addEventListener('geoloc:coordonnees', function (evenement) {
					var position = [evenement.detail.lat, evenement.detail.lon];
					marqueur.setLatLng(position);
					carte.panTo(position);
				});
			}
			groupe.addLayer(marqueur);
		});
		groupe.addTo(carte);

		var bornes = groupe.getBounds();
		var premier = donnees.marqueurs[0];
		var centre = donnees.centre
			? [donnees.centre.lat, donnees.centre.lon]
			: (premier ? [premier.lat, premier.lon] : [46.5, 2.5]);
		if (donnees.zoom !== null) {
			carte.setView(centre, donnees.zoom);
		} else if (bornes.isValid()) {
			carte.fitBounds(bornes, { maxZoom: 13 });
			if (donnees.centre) {
				carte.panTo(centre);
			}
		} else {
			carte.setView(centre, 5);
		}
	}

	document.addEventListener('openstreetmap_loaded', lancer);
	document.addEventListener('openstreetmap_allowed', lancer);
	document.addEventListener('click', function (evenement) {
		if (evenement.target.id === 'carte-autoriser') {
			tarteaucitron.userInterface.respond(document.getElementById('openstreetmapAllowed'), true);
		}
	});
}());
