<?php
	$host = "localhost";
	$user = "admin";
	$pass = "admin123";
	$dbname = "shopnet";

	$pdo = new PDO("mysql:host=".$host.";dbname=".$dbname.";charset=utf8", $user, $pass);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

	$queryTipPopusta = 'CREATE TABLE tippopusta(
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		naziv VARCHAR(255) NOT NULL,
		CONSTRAINT pk_id PRIMARY KEY (id)
	);';

	$queryKorisnik = 'CREATE TABLE korisnik (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		korime VARCHAR(50) NOT NULL,
		sifra VARCHAR(255) NOT NULL,
		ime VARCHAR(30) NOT NULL,
		prezime VARCHAR(30) NOT NULL,
		brtel VARCHAR(30) NOT NULL,
		poeni INT UNSIGNED DEFAULT 0,
		loklat DOUBLE(15,10) DEFAULT 0,
		loklng DOUBLE(15,10) DEFAULT 0,
		lokvreme INT UNSIGNED DEFAULT 0,
		token VARCHAR(64),
		CONSTRAINT pk_id PRIMARY KEY (id),
		CONSTRAINT un_korime UNIQUE (korime),
		CONSTRAINT un_token UNIQUE (token)
	);';

	$queryPrijateljSa = 'CREATE TABLE prijateljsa(
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		osoba INT UNSIGNED,
		prijatelj INT UNSIGNED,
		CONSTRAINT pk_id PRIMARY KEY (id),
		CONSTRAINT fk_osoba FOREIGN KEY (osoba) REFERENCES korisnik(id),
		CONSTRAINT fk_prijatelj FOREIGN KEY (prijatelj) REFERENCES korisnik(id)
	);';
	
	$queryPopust = 'CREATE TABLE popust(
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		profil INT UNSIGNED NOT NULL,
		loklat DOUBLE(15,10) NOT NULL,
		loklng DOUBLE(15,10) NOT NULL,
		vremedodavanja INT UNSIGNED NOT NULL,
		trajedo INT UNSIGNED DEFAULT 0,
		velicinapopusta INT UNSIGNED NOT NULL,
		tippopusta INT UNSIGNED NOT NULL,
		opispopusta TEXT DEFAULT NULL,
		CONSTRAINT pk_id PRIMARY KEY (id),
		CONSTRAINT fk_profil FOREIGN KEY (profil) REFERENCES korisnik(id),
		CONSTRAINT fk_tippopusta FOREIGN KEY (tippopusta) REFERENCES tippopusta(id)
	);';

	$queryKomentar = 'CREATE TABLE komentar(
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		profil INT UNSIGNED NOT NULL,
		popust INT UNSIGNED NOT NULL,
		ocena INT UNSIGNED NOT NULL,
		opis TEXT,
		CONSTRAINT pk_id PRIMARY KEY (id),
		CONSTRAINT fk_profil FOREIGN KEY (profil) REFERENCES korisnik(id),
		CONSTRAINT fk_popust FOREIGN KEY (popust) REFERENCES popust(id)
	);';

	$tipoviPopusta = ['Zenska odeca', 'Muska odeca', 'Decija odeca', 'Tehnika', 'Hrana', 'Alat', 'Namestaj'];

	$queryDropAll = 'SET FOREIGN_KEY_CHECKS = 0;

		DROP TABLE IF EXISTS tippopusta;
		DROP TABLE IF EXISTS korisnik;
		DROP TABLE IF EXISTS prijateljsa;
		DROP TABLE IF EXISTS popust;
		DROP TABLE IF EXISTS komentar;

		SET FOREIGN_KEY_CHECKS = 1
	;';
	
	$pdo->query($queryDropAll);

	$pdo->query($queryTipPopusta);
	$pdo->query($queryKorisnik);
	$pdo->query($queryPrijateljSa);
	$pdo->query($queryPopust);
	$pdo->query($queryKomentar);

	foreach ($tipoviPopusta as $popust) {
		$stmt = $pdo->prepare('INSERT INTO tippopusta (naziv) VALUES (?)');
		$stmt->execute([$popust]);
	}
?>