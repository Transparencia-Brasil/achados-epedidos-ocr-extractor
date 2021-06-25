##  script de extração de textos para a indexação na busca do achados e pedidos
- copiar .env.example para .env e configurar variáveis conforme documentado abaixo
- instalação da aplicação com as dependências dentro do docker
```
docker-compose build
```

- rodando o script
```
docker-compose run app php indexador.php
```

- rodando script passando Codigo do registro na tabela pedidos_anexos
```
docker-compose run app php indexador.php [Codigo.pedidos_anexos(Int)]
```
- variáveis de ambiente: 

DB_HOST=(Endereço do banco de dados)\
DB_PORT=(Porta do banco de dados)\
DB_DATABASE=(Nome do banco de dados)\
DB_USERNAME=(Usuario do banco de dados)\
DB_PASSWORD=(Senha do banco de dados)\
API_URL=(Endereco da API que irá popular o Elastic Serch)\
FILES_PATH=(Caminho absoluto do bucket montado)\
BUCKET_PATH=(Caminho não montado (acesso direto ao bucket) ex do google cloud: gs://achados-e-pedidos-bucket/)\

- Caminhos fixos:
O caminho do bucket (BUCKET_PATH) e files (FILES_PATH) devem vir precedidos pela pasta pedidos/
É obrigatório ter essa pasta pedidos/ criada com os arquivos que serão indexados.

- logs:
-- log simplificado com status de cada arquivo que foi indexado + timestamp + arquivo
Criado com esse critério: /RltIndexador-" . date('d-m-Y-H-i')  . ".csv"
Para armazenar o histórico, cada novo início do script será gerado um novo arquivo na rais do FILES_PATH

-- log completo por arquivo: Quando um arquivo apresenta falha, o detalhe do log fica armazenado neste arquivo.É gerado conforme a data na seguinde estrutura de pastas:
FILES_PATH ocr_indexador/logs/d-m-yyyy-h-n (ex:28-05-2021-17-56)

- /convertidos: armazena logs dos arquivos convertidos para posterior verificação



