<?php
	use \Psr\Http\Message\ServerRequestInterface as Request;
	use \Psr\Http\Message\ResponseInterface as Response;

	require 'vendor/autoload.php';
	session_start();

	function resize_image($file, $w, $h, $crop=FALSE) {
		list($width, $height) = getimagesize($file);
		$r = $width / $height;
		if ($crop) {
			if ($width > $height) {
				$width = ceil($width-($width*abs($r-$w/$h)));
			} else {
				$height = ceil($height-($height*abs($r-$w/$h)));
			}
			$newwidth = $w;
			$newheight = $h;
		} else {
			if ($w/$h > $r) {
				$newwidth = $h*$r;
				$newheight = $h;
			} else {
				$newheight = $w/$r;
				$newwidth = $w;
			}
		}
		$src = imagecreatefromjpeg($file);
		$dst = imagecreatetruecolor($newwidth, $newheight);
		imagealphablending($dst,true); //added
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
		//added below lines
		$mask = imagecreatetruecolor($newwidth, $newheight);
		$transparent = imagecolorallocate($mask, 255,0,0);
		imagecolortransparent($mask, $transparent);
		imagefilledellipse($mask, $newwidth/2, $newheight/2, $newwidth, $newheight, $transparent);
		$red = imagecolorallocate($mask, 0, 0, 0);
		imagecopymerge($dst, $mask, 0, 0, 0, 0, $newwidth, $newheight, 100);
		imagecolortransparent($dst, $red);
		imagefill($dst, 0, 0, $red);


		return $dst;
	}

	//app configuration
	$config['displayErrorDetails'] = true;
	$config['addContentLengthHeader'] = false;

	$config['database']['host']   = "localhost";
	$config['database']['user']   = "admin";
	$config['database']['pass']   = "admin123";
	$config['database']['dbname'] = "shopnet";

	//create app
	$app = new \Slim\App(["settings" => $config]);
	$container = $app->getContainer();
	//inject database connection dependency module into the app; module will be named 'database'
	$container['database'] = function($appContainer){
		$database = $appContainer['settings']['database']; //get the database settings from the settings inside the container;
		$options = array(PDO::MYSQL_ATTR_FOUND_ROWS => true);
		$pdo = new PDO("mysql:host=".$database['host'].";dbname=".$database['dbname'].";charset=utf8", $database['user'], $database['pass'], $options);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$pdo->setAttribute(PDO::MYSQL_ATTR_FOUND_ROWS, true);
		return $pdo;
	};
	$container['sessionManager'] = function($appContainer){
		//create and return an object
	};

	//add all application-level middleware
	/*rka-ip-address middleware*/
	$app->add(new RKA\Middleware\IpAddress(true, []));


	$app->get('/tipovi', function($request, $response, $args){
		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM tippopusta ORDER BY id ASC;');
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		return $response->withJson($rows, 200);
	});

	$app->post('/novikorisnik', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		file_put_contents('siroviJSON.txt', $rawData);
		//return $response;
		
		$jsonresponse = array();
		$jsonresponse["status"] = "FAIL";

		//generisanje hasha sifre
		$hashSifre = password_hash($data["sifra"], PASSWORD_DEFAULT);
		//unos u bazu
		try{
			$pdo = $this->database;
			$stmt = $pdo->prepare('INSERT INTO korisnik (korime, sifra, ime, prezime, brtel) VALUES (?, ?, ?, ?, ?);');
			$stmt->execute([
					$data["korime"],
					$hashSifre,
					$data["ime"],
					$data["prezime"],
					$data["brtel"]
				]);
			$insertID = $pdo->lastInsertId();
			$jsonresponse["status"] = "OK";
			$jsonresponse["id"] = $insertID;
			if($insertID == 0) return $response->withJson($jsonresponse, 400);
			//upis slike na disk
			$imgFile = fopen('slike/'.$insertID.'.jpg', 'wb');
			fwrite($imgFile, base64_decode($data["slika"]));
			fclose($imgFile);

			return $response->withJson($jsonresponse, 200);
		}
		catch(PDOException $e){
			$jsonresponse["status"] = "INSERT FAILED";
			return $response->withJson($jsonresponse, 400);
		}	
	});

	$app->post('/login', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$jsonresponse = array();
		$jsonresponse["token"] = 0;

		$korime = $data["korime"];
		$sifra = $data["sifra"];

		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT sifra FROM korisnik WHERE korime = ?;');
		$stmt->execute([$korime]);
		$sifraIzBaze = $stmt->fetchColumn();
		if($sifraIzBaze)
			if(password_verify($sifra, $sifraIzBaze)){
				$jsonresponse["token"] = uniqid(true);
				$stmt = $pdo->prepare('UPDATE korisnik SET token = ? WHERE korime = ?;');
				$stmt->execute([
					$jsonresponse["token"],
					$korime
				]);
				return $response->withJson($jsonresponse, 200);
			}
			else
				return $response->withJson($jsonresponse, 400);
		else
			return $response->withJson($jsonresponse, 400);
	});

	$app->post('/checktoken', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$token = $data["token"];

		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE token = ?');
		$stmt->execute([$token]);
		$row = $stmt->fetch();
		if($row)
			return $response->withStatus(200);
		else
			return $response->withStatus(400);
	});

	$app->post('/userinfo', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$token = $data["token"];
		$jsonresponse = array();

		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE token = ?');
		$stmt->execute([$token]);
		$row = $stmt->fetch();
		if($row){
			//citamo sliku i base64_encode
			$userID = $row["id"];
			$imagePath = 'slike/'.$userID.'.jpg';
			ob_start();
			imagepng(resize_image($imagePath, 256, 256, true));
			$imageBinary = ob_get_clean();
			$imageStr = base64_encode($imageBinary);
			//$imageStr = str_replace("=", "", $imageStr);

			$jsonresponse["slika"] = $imageStr;
			$jsonresponse["korime"] = $row["korime"];
			return $response->withJson($jsonresponse, 200);
		}
		else
			return $response->withStatus(400);
	});

	$app->post('/updateuser', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$token = $data["token"];
		$jsonresponse = array();

		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE token = ?');
		$stmt->execute([$token]);
		$row = $stmt->fetch();
		if($row){
			$userID = $row["id"];
			$stmt = $pdo->prepare('UPDATE korisnik SET loklat = ?, loklng = ?, lokvreme = ? WHERE id = ?;');
			$stmt->execute([
				$data["loklat"],
				$data["loklng"],
				time(),
				$userID
			]);
			return $response->withStatus(200);
		}
		else
			return $response->withStatus(400);
	});

	$app->post('/dodajpopust', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$token = $data["token"];
		$jsonresponse = array();

		//proveri token
		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE token = ?');
		$stmt->execute([$token]);
		$row = $stmt->fetch();
		if($row){
			//nabavi id korisnika
			$userID = $row["id"];
			//nabavi id tipa popusta
			$stmt = $pdo->prepare('SELECT * FROM tippopusta WHERE naziv = ?');
			$stmt->execute([$data["tippopusta"]]);
			$rowPopust = $stmt->fetch();
			if(!$rowPopust)
				return $response->withStatus(400);
			$tipID = $rowPopust["id"];
			//ubaci novi popust u bazu
			$stmt = $pdo->prepare('INSERT INTO popust (profil, loklat, loklng, vremedodavanja, trajedo, velicinapopusta, tippopusta, opispopusta) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ');
			$stmt->execute([
					$userID,
					$data["lat"],
					$data["lng"],
					time(),
					$data["trajedo"],
					$data["velicinapopusta"],
					$tipID,
					$data["opispopusta"]
				]);
			$insertID = $pdo->lastInsertId();
			if($insertID < 1)
				return $response->withStatus(400);
			return $response->withJson($jsonresponse, 200);
		}
		else
			return $response->withStatus(400);
		return $response;
	});

	$app->post('/popusti', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$token = $data["token"];
		$jsonresponse = array();

		//proveri token
		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE token = ?');
		$stmt->execute([$token]);
		$row = $stmt->fetch();
		if($row){
			$stmt = $pdo->prepare('SELECT * FROM popust;');
			$stmt->execute([]);
			$rows = $stmt->fetchAll();
			return $response->withJson($rows, 200, JSON_NUMERIC_CHECK);
		}
		else
			return $response->withStatus(400);
		return $response;
	});
	$app->post('/popustipretraga', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$token = $data["token"];
		$query = $data["upit"];
		$query = '%'.$query.'%';
		
		$jsonresponse = array();

		//proveri token
		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE token = ?');
		$stmt->execute([$token]);
		$row = $stmt->fetch();
		if($row){
			$stmt = $pdo->prepare('SELECT * FROM popust INNER JOIN tippopusta ON popust.tippopusta = tippopusta.id WHERE (tippopusta.naziv LIKE :query) OR (popust.opispopusta LIKE :query);');
			$stmt->bindParam(':query', $query, PDO::PARAM_STR);
			$stmt->execute();
			$rows = $stmt->fetchAll();
			return $response->withJson($rows, 200, JSON_NUMERIC_CHECK);
		}
		else
			return $response->withStatus(400);
		return $response;
	});
	$app->post('/prijatelji', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$token = $data["token"];
		$jsonresponse = array();

		//proveri token
		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE token = ?');
		$stmt->execute([$token]);
		$row = $stmt->fetch();
		if($row){
			//izvuci ID-eve prijatelja
			$userID = $row["id"];
			$stmt = $pdo->prepare('SELECT * FROM prijateljsa WHERE (osoba = ? OR prijatelj = ?);');
			$stmt->execute([$userID, $userID]);
			$rows = $stmt->fetchAll();
			if($rows){
				foreach($rows as $row){
					//izvuci ID prijatelja
					$prijateljID = -1;
					if($userID == $row["osoba"])
						$prijateljID = $row["prijatelj"];
					elseif($userID == $row["prijatelj"])
						$prijateljID = $row["osoba"];				
					//nadji u bazi
					$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE id = ?;');
					$stmt->execute([$prijateljID]);
					$rowPrijatelj = $stmt->fetch();
					if($rowPrijatelj){
						$stmtLajkovi = $pdo->prepare("SELECT count(*) FROM popust WHERE profil = ?");
						$stmtLajkovi->execute([$prijateljID]);
						$brojPoena = 5 * $stmtLajkovi->fetchColumn();
						$stmtLajkovi = $pdo->prepare("SELECT count(*) FROM lajk INNER JOIN popust ON lajk.popust = popust.id WHERE popust.profil = ?");
						$stmtLajkovi->execute([$prijateljID]);
						$brojPoena += $stmtLajkovi->fetchColumn();

						$prijatelj = array();
						$prijatelj["id"] = $rowPrijatelj["id"];
						$prijatelj["korime"] = $rowPrijatelj["korime"];
						$prijatelj["ime"] = $rowPrijatelj["ime"];
						$prijatelj["prezime"] = $rowPrijatelj["prezime"];
						$prijatelj["poeni"] = $brojPoena;
						$prijatelj["loklat"] = $rowPrijatelj["loklat"];
						$prijatelj["loklng"] = $rowPrijatelj["loklng"];
						$prijatelj["lokvreme"] = $rowPrijatelj["lokvreme"];

						$tempPrijateljID = $rowPrijatelj["id"];
						$imagePath = 'slike/'.$tempPrijateljID.'.jpg';
						ob_start();
						imagepng(resize_image($imagePath, 96, 96, true));
						$imageBinary = ob_get_clean();
						$imageStr = base64_encode($imageBinary);
						$prijatelj["slika"] = $imageStr;

						$jsonresponse[] = $prijatelj;
					}
				}
			}
			return $response->withJson($jsonresponse, 200, JSON_NUMERIC_CHECK);
		}
		else
			return $response->withStatus(400);
		return $response;
	});

	$app->post('/lajk', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$token = $data["token"];
		$jsonresponse = array();

		//proveri token
		$pdo = $this->database;
		$stmt = $pdo->prepare('SELECT * FROM korisnik WHERE token = ?');
		$stmt->execute([$token]);
		$row = $stmt->fetch();
		if($row){
			$userID = $row['id'];
			$popustID = $data['id'];
			
			$stmt = $pdo->prepare("SELECT * FROM lajk WHERE profil = ? AND popust = ?");
			$stmt->execute([$userID, $popustID]);
			$redLajk = $stmt->fetch();
			if(!$redLajk){
				$stmt = $pdo->prepare("INSERT INTO lajk (profil, popust) VALUES (?, ?)");
				$stmt->execute([$userID, $popustID]);
				return $response->withStatus(200);
			}
		}
		else
			return $response->withStatus(400);
		return $response;
	});

	$app->post('/logout', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		$jsonresponse = array();
		$jsonresponse["token"] = 0;

		$token = $data["token"];

		$pdo = $this->database;
		$stmt = $pdo->prepare('UPDATE korisnik SET token = NULL WHERE token = ?');
		$stmt->execute([$token]);
		return $response->withJson($jsonresponse, 200);
	});

	$app->post('/test', function($request, $response, $args){
		$rawData = urldecode($request->getBody());
		$data = json_decode($rawData, true);

		file_put_contents('pod.txt', $data["slika"]);
		return $response->getBody()->write($data["slika"]);
	});
	$app->get('/test', function($request, $response, $args){
		return $response->getBody()->write("Sve radi");
	});
	
	//run the app
	$app->run();

	//comments
	/*
	To get JSON data:
	$parsedBody = $request->getParsedBody(); - native parsing to PHP formats for JSON, XML and URL-encoded data (JSON into assoc array)
	To return JSON data:
	$data = array('name' => 'Bob', 'age' => 40);
	$newResponse = $oldResponse->withJson($data[,$status (HTTP STATUS CODE), $encodingOptions (SAME OPTIONS AS json_encode)]);

	use  htmlspecialchars()
	*/
?>