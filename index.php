<?php


try {
    $conexao = new \PDO("mysql:host=localhost;dbname=curso;charset=utf8", 'root', '');
    $conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\PDOException $e) {
    echo $e->getMessage();
}


$sql = "SELECT * FROM users";
$stmt = $conexao->prepare($sql);
$stmt->execute();

$resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($resultado as $linha) {
    var_dump($linha);
}



