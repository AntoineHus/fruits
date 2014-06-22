<?php
require('config.php');
$bdd = new PDO(SMSDSN, SMSUSERNAME, SMSPASSWORD);

echo "# Fruits v5
## Identification des series (tvnamer)
## " . date('Y-m-d H:i:s') . "\n";

// SQL
$reqAddSerie = $bdd->prepare("INSERT INTO series
	VALUES('', :nom, :tmdbid,:tnbseasons, :tpopularity, :tfirstdate, :tlastdate, :tepisode_run_time, :tgenres, :tin_production,:tnetwork,:torigin_country,:toverview)");
$reqAddSaison = $bdd->prepare("INSERT INTO series_saisons
	VALUES('',:serie, :saison)");
$reqAddEpisode = $bdd->prepare("INSERT INTO series_episodes
	VALUES(:file, :saison, :nep,'','2013-01-01')");

$reqAllFiles = $bdd->prepare("SELECT id, nom, chemin_complet
	FROM fichiers
	WHERE LOWER(chemin_complet) LIKE '%/serie%' AND supprime = 0 AND type <> 'dossier' AND date_depose > DATE_SUB(NOW(), INTERVAL 2 DAY) AND id NOT IN (SELECT fichier FROM series_episodes)");
$reqAllFiles->execute();
$files = $reqAllFiles->fetchAll();
$reqAllFiles->closeCursor();


$reqAllSeries = $bdd->prepare("SELECT series.id, series.tmdbid, series.nom, sa.id AS saison, sa.numero
	FROM series
	LEFT JOIN series_saisons AS sa
	ON sa.serie = series.tmdbid");
$reqAllSeries->execute();
$seriesAndSa = $reqAllSeries->fetchAll();
$reqAllSeries->closeCursor();

$series = array();
$nomSerie = array();
$not = array();
foreach ($seriesAndSa as $s) {
	$nomSerie[$s['nom']] = $s['tmdbid'];
	if (!array_key_exists($s['tmdbid'], $series)) {
		$series[$s['tmdbid']] = array('id' => $s['tmdbid']);
	}
	$series[$s['tmdbid']][$s['numero']] = $s['saison'];
}

$total = count($files);
$cinqp = ceil($total / 20);
$last = (-1) * $cinqp * 3;

echo $total . " fichiers à identifier\n\n";
$i = 0;
foreach ($files as $f) {
	$pourc = ceil($i / $total * 100);
	if ($pourc % 10 == 0 and ($i - $last) > $cinqp) {
		$last = $i;
		echo "\n" . $pourc . '%  ';
	}
	$i++;
	
	if (in_array(strtolower($f['nom']), $useless) or in_array(strtolower(preg_replace('#^.+\.([a-zA-Z0-9]+)$#isU', '$1', $f['nom'])), $uselessExt)) {
    	continue;
	}

	exec('rm -rf tvnamer/files/*');
	touch('tvnamer/files/' . $f['nom']);
	$tvnamer = shell_exec('tvnamer -af ' . escapeshellarg('tvnamer/files/' . $f['nom']));
	if (preg_match('!# Detected series:!', $tvnamer)) {
		$nom = trim(preg_replace('!^.+# Detected series: (.+) \(season: ([0-9]+), episode: ([0-9]+)\).+$!isU', '$1', $tvnamer));
		$nsaison = trim(preg_replace('!^.+# Detected series: (.+) \(season: ([0-9]+), episode: ([0-9]+)\).+$!isU', '$2', $tvnamer));
		$nep = trim(preg_replace('!^.+# Detected series: (.+) \(season: ([0-9]+), episode: ([0-9]+)\).+$!isU', '$3', $tvnamer));
		
		//Petites corrections
		$nom = str_replace('babylon5', 'babylon.5', $nom);
		$nom = str_replace('L0st', 'Lost', $nom);
	} else {
		continue;
	}
	//echo $nom . " S" . $nsaison . "E" . $nep . "\n";
	
	
	// La série existe-t-elle en bdd ?
	if (!array_key_exists($nom, $nomSerie)) {
		$searchApi = json_decode(file_get_contents('https://api.themoviedb.org/3/search/tv?api_key=10693a5e1e693837a6c36153f260d8d3&query=' . urlencode($nom), false, $cxContext));
		if (count($searchApi->results) > 0) {
			if (levenshtein($nom, strtolower($searchApi->results[0]->original_name)) <= ceil(strlen($nom)*100000)) {
				$infos = $searchApi->results[0];
				$reqAddSerie->bindValue(':nom', $infos->original_name);
				$reqAddSerie->bindValue(':tmdbid', $infos->id);
				$reqAddSerie->bindValue(':tnbseasons', 0);
				$reqAddSerie->bindValue(':tpopularity', $infos->popularity);
				$reqAddSerie->bindValue(':tfirstdate', $infos->first_air_date);
				$reqAddSerie->bindValue(':tlastdate', '2013-01-01');
				$reqAddSerie->bindValue(':tepisode_run_time', 0);
				$reqAddSerie->bindValue(':tgenres', '');
				$reqAddSerie->bindValue(':tin_production', false);
				$reqAddSerie->bindValue(':tnetwork', '');
				$reqAddSerie->bindValue(':torigin_country', '');
				$reqAddSerie->bindValue(':toverview', '');
				$reqAddSerie->execute();
				if (!array_key_exists($infos->id, $series)) {
					$series[$infos->id] = array('id' => $infos->id);
				}
				$nomSerie[$nom] = $infos->id;
				
				copy('https://image.tmdb.org/t/p/original/' . $infos->poster_path, '../api/data/series/poster/' . $infos->tmdbid . '.jpg', $cxContext);
				copy('https://image.tmdb.org/t/p/w300/' . $infos->poster_path, '../api/data/series/poster/' . $infos->tmdbid . '_w300.jpg', $cxContext);
				
				$reqAddSerie->closeCursor();
				echo 'S';
			} else {
				echo 'C';
				$not[$nom] = true;
				continue;
			}
		} else {
			//echo "\n" . $nom . ' -- ' . $f['chemin_complet'] . ' -- ';
			echo 'N';
			$not[$nom] = true;
			continue;
		}
	}
	
	// La saison existe-t-elle en bdd ?
	if (!array_key_exists($nsaison, $series[$nomSerie[$nom]])) {
		$reqAddSaison->bindValue(':serie', $series[$nomSerie[$nom]]['id']);
		$reqAddSaison->bindValue(':saison', $nsaison);
		$reqAddSaison->execute();
		$series[$nomSerie[$nom]][$nsaison] = $bdd->lastInsertId();
		$reqAddSaison->closeCursor();
		echo 's';
	}
	
	
	
	// On ajoute l'épisode
	$reqAddEpisode->bindValue(':file', $f['id']);
	$reqAddEpisode->bindValue(':saison', $series[$nomSerie[$nom]][$nsaison]);
	$reqAddEpisode->bindValue(':nep', $nep);
	$reqAddEpisode->execute();
	$reqAddEpisode->closeCursor();
	echo 'e';
	
	//echo $nom . " -- " . $f['chemin_complet'] . "\n";
}
