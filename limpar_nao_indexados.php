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

class PedidoAnexoLimpar {
    private $DbConn;
    private $ApiClient;


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
        $this->ApiClient = new \GuzzleHttp\Client([
            'base_uri' => $_ENV['API_URL'],
            'timeout'  => 60.0,
        ]);
        
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
     * Lista todos os Anexos com falha apartir do Banco de Dados
     */
    public function BuscarAnexos()
    {
		//auto reconnect if MySQL server has gone away
        if (!mysqli_ping($this->DbConn)) $this->Reconectar();
		
        $result = $this->DbConn->query("Select CodigoStatusExportacaoES, Codigo, Arquivo From pedidos_anexos Where CodigoStatusExportacaoES == 'falha'");

        return $result;
    }

    public function BuscarAnexosCSV($arquivo) {
        $result = array();

        $csvHandle = fopen($arquivo, "r");
        if ($csvHandle) {
            $iLine = 0;
            while (($line = fgets($csvHandle)) !== false) {
                $iLine++;

                if($iLine >= 2) { // Pula o Sep E o Header 
                    echo $iLine . ".";        
                    $conteudo = explode(',', $line);
                    $codigo = $conteudo[1];
                    $arquivo = $conteudo[2];

                    $result[] = array("Codigo" => $codigo, "Arquivo" => $arquivo);
                }
            }

            echo "\n";

            fclose($csvHandle);
        }

        return $result;
    }

    protected function AnexoFoiIndexado($codigo)
    {
        try {
            $r = $this->ApiClient->request('GET', API_URL . "/anexos/buscar/" . $codigo);
            echo "Indexacao: " . $r->getStatusCode() . "\n";
			
            $anexo = json_decode($r->getBody());
          //  print_r($anexo);
            if(strlen($anexo->anexos_conteudo_arquivo) > 0) {
                return 1;
            } else {
                return 2;
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
               echo (\GuzzleHttp\Psr7\str($e->getResponse())) . "\n";            }
            else {
                echo "Falhou \n";
            }

            return 0;
        }
    }


    public function Limpar($arquivo) {
        echo "Iniciando ... \n";
        $resultado = array();

        if(strlen($arquivo) <= 0) {
            $resultado = $this->BuscarAnexos();
        }
        else {
            echo "Importando CSV: $arquivo \n";
            $resultado = $this->BuscarAnexosCSV($arquivo);
        }

        echo "Procurando .. \n";
        foreach ($resultado as $i => $anexo) {
            echo "Codigo: " . $anexo["Codigo"] . "\n";

            $anexado = $this->AnexoFoiIndexado($anexo["Codigo"]);
            if($anexado == 0 ) {
                echo "Não indexado! \r\n";
                $this->DbConn->query('DELETE FROM es_pedidos_anexos Where CodigoPedidoAnexo = ' . $anexo["Codigo"]);
            } else if($anexado == 1) {
                echo "Já foi Indexado! \r\n";
                $this->DbConn->query("UPDATE pedidos_anexos Set CodigoStatusExportacaoES = 'extraido'  Where Codigo = " . $anexo["Codigo"]);
            }
        }
    }
}

$pedido = new PedidoAnexoLimpar();
$pedido->Init();
$pedido->Limpar($argv[1]);
