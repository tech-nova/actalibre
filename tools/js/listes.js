// Remplit une liste déroulante quand on choisit le type d'actes.
document.addEventListener('click', function (evenement) {
	var choix = evenement.target;
	if (!choix.matches || !choix.matches('input[data-liste-cible]')) {
		return;
	}
	var liste = document.getElementById(choix.dataset.listeCible);
	var attente = document.getElementById(choix.dataset.listeAttente);
	var adresse = new URL(choix.dataset.listeAdresse || window.location.href, window.location.href);
	adresse.searchParams.set('liste', '1');
	adresse.searchParams.set('valeur', choix.value);
	attente.style.visibility = 'visible';
	fetch(adresse, { credentials: 'same-origin' })
		.then(function (reponse) {
			return reponse.json();
		})
		.then(function (options) {
			liste.options.length = 0;
			options.forEach(function (option) {
				liste.add(new Option(option.text, option.value));
			});
		})
		.catch(function () {
			window.alert("La liste n'a pas pu être chargée.");
		})
		.finally(function () {
			attente.style.visibility = 'hidden';
		});
});
