<?php

// -
// Relatorio do Indexador de OCR
// -

namespace Google\Cloud\Samples\Vision;

require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/.env');

define("FILES_PATH", $_ENV["FILES_PATH"]);
define("BUCKET_PATH", $_ENV["BUCKET_PATH"]);
define("API_URL", $_ENV["API_URL"]);
ini_set('memory_limit', '1024M'); 

class PedidoAnexoRelatorio {
    private $DbConn;

    public function Init()
    {
		$this->Conectar();
    }

    protected function Desconectar() {
        mysqli_close($this->DbConn);
    }
	
	protected function Reconectar() {
		$this->Desconectar();
		$this->Conectar();
	}
	
	protected function Conectar() {
		if (isset($_ENV['MYSQL_ATTR_SSL_CA']) && !empty($_ENV['MYSQL_ATTR_SSL_CA'])) {
            $this->DbConn = mysqli_init();
            $this->DbConn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
            $this->DbConn->ssl_set(NULL, NULL, $_ENV['MYSQL_ATTR_SSL_CA'], NULL, NULL);
            $this->DbConn->real_connect($_ENV['DB_HOST'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DB_DATABASE']);
        } else {
            $this->DbConn =  new \mysqli($_ENV['DB_HOST'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DB_DATABASE']);
            if ($this->DbConn->connect_error) {
                die("DB Connection failed: " . $this->DbConn->connect_error . "\n");
            }
         }

		$this->DbConn->query("SET NAMES 'utf8'");
        $this->DbConn->query('SET character_set_connection=utf8');
        $this->DbConn->query('SET character_set_client=utf8');
        $this->DbConn->query('SET character_set_results=utf8');
	}


    protected function CaminhoDoLog($row) {
        $caminho = mb_convert_encoding($row["Arquivo"], "UTF-8");     
        $caminhoParts = pathinfo($caminho);
        return $caminhoParts['filename'] . ".log";
    }


     /**
     * Lista todos os Anexos
     */
    public function BuscarAnexos()
    {
		//auto reconnect if MySQL server has gone away
        if (!mysqli_ping($this->DbConn)) $this->Reconectar();
		
        $result = $this->DbConn->query("Select CodigoStatusExportacaoES, Codigo, Arquivo From pedidos_anexos Where CodigoStatusExportacaoES != 'extraido'");

        return $result;
    }

    protected function ColetarLogs() {
        $cmd  = "tree '" . FILES_PATH . "/ocr_indexador/logs' -f -i";
        $result = "";

        ob_start();
        passthru($cmd);
        $result = ob_get_contents();
        ob_end_clean();

    //    echo "COLETA: " . $result;

        return explode("\n", $result);
    }

    public function Gerar() {
        echo "Coletando ... \n";

        $resultado = $this->BuscarAnexos();
        $anexos = $resultado->fetch_all(MYSQLI_ASSOC); // faster;

        echo "Coletando os Logs ...\n";
        $listagemLogs = $this->ColetarLogs();

        echo "Analisando os Relatórios ..\n";
        $pasta = FILES_PATH;

        if ($handle = opendir($pasta)) {
            while (false !== ($entry = readdir($handle))) {        
                if ($entry != "." && $entry != "..") {
                    if(strpos($entry, ".csv") !== false) {
                        echo "$entry\n";

                        $csvHandle = fopen($pasta . "/" .  $entry, "r");
                        if ($csvHandle) {
                            $iLine = 0;
                            while (($line = fgets($csvHandle)) !== false) {
                                $iLine++;

                                if($iLine >= 2) { // Pula o Sep E o Header                                    
                                    echo $iLine . ".";
                                    $conteudo = explode(',', $line);
                                    $codigo = $conteudo[1];

                                    // - Atualiza a Lista de Anexos com o Estado encontrado no relatorio //
                                    $indice = array_search($codigo, array_column($anexos, "Codigo"));
                                    if($indice !== false) {
                                        $anexos[$indice]["ProcessadoEm"] = $conteudo[0];
                                        $anexos[$indice]["Mensagem"] = $conteudo[4];
                                    }
                                }
                            }

                            echo "\n";

                            fclose($csvHandle);
                        }
                    }
                }
            }
        
            closedir($handle);
        } 

        // Aplica a Mensagem Padrao para os Anexos que nao tem Entradas no Relatorio
        foreach ($anexos as $i => $anexo) {
            if(!isset($anexos[$i]["Mensagem"])) {
                $anexos[$i]["Mensagem"] = "Erro no processamento / falta de recurso ";
            }
        }

        // Recolhe o Caminho dos Logs 
        echo "Procurando os Logs .. Isto pode demorar ... \n";

        foreach ($anexos as $i => $anexo) {
            $codigo = $anexo["Codigo"];
            echo "Procurando Logs para $codigo \n";            
            $logNome = $this->CaminhoDoLog($anexo);
            $logs = "Não encontrado!";

            $procura = array_filter($listagemLogs, function($element) use($logNome){
                return isset($element) && strpos($element, $logNome) !== false;
            });

            $logs = implode(" | ", $procura);
            $anexos[$i]["Logs"] = $logs;
        }

        // 
        echo "Salvando JSON... \n";
        file_put_contents(FILES_PATH . "/RltIndexadorFinal.json", json_encode($anexos));

        echo "Exportando CSV ... \n";
        $headers = ['Estado', 'Codigo', 'Arquivo', 'Mensagem', 'Logs'];
        $file = fopen(FILES_PATH . "/RltIndexadorFinal.csv", "w");
        fputcsv($file, $headers);
        foreach($anexos as $anexo){
            fputcsv($file, array_values($anexo));
        }
        fclose($file);
    }
}

$pedido = new PedidoAnexoRelatorio();
$pedido->Init();
$pedido->Gerar();