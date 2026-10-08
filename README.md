# Armário de Medicamentos (medcabinet)

App Nextcloud para o que há de medicamentos em casa: que caixas existem, quantas unidades
restam, até quando são válidas, quem as toma e — o que mais falta depois — **para que
serviram**.

## A ideia

### A finalidade não é do medicamento, é do episódio

O mesmo anti-inflamatório serviu para uma dor de dentes em março e para uma entorse em
setembro. Guardar o "para quê" no medicamento perde essa distinção; guardá-lo num
**episódio** — uma pessoa, um motivo, um intervalo, quem assistiu — é o que torna o registo
útil meses depois.

Assim, procurar por *"otite"* ou por *"amoxicilina"* devolve factos com proveniência:

> Em março de 2026, para a Maria, com otite, o Dr. X receitou Amoxicilina 500 durante 8 dias.

### É um registo, não um conselho

A app responde **"o que foi usado, quando e por indicação de quem"**. Nunca "o que deves
tomar". Não é timidez: um registo com proveniência vale mais do que uma sugestão sem fonte,
e serve para falares com um médico — não para dispensares de falar com ele.

### A validade impressa deixa de valer quando se abre

Esta é a regra que molda o resto. Um xarope, um colírio ou uma insulina duram semanas depois
de abertos, mesmo com a caixa a dizer 2028:

```
validade efetiva = min(impressa, abertura + dias após abertura)
```

Abrir **nunca** prolonga a validade. E quando uma embalagem está aberta, é de uma forma que
se estraga, e não se sabe quantos dias dura, o estado é **desconhecido** — não a data
impressa. Devolver a impressa seria dar por boa uma data que a abertura já invalidou, e
ninguém ficava a saber que faltava um dado.

A regra aplica-se só às formas que se estragam. Um blister não muda de validade por se tirar
um comprimido; aplicá-la a tudo punha metade do armário como desconhecido.

### Outras decisões que evitam números errados

- **Stock conta-se em unidades, não em caixas.** Meia caixa e meio comprimido existem.
- **A percentagem que resta só se calcula sabendo o total.** Sem ele, "um quarto" não quer
  dizer nada.
- **Gasta-se primeiro o que expira mais cedo**, não o que se comprou primeiro — e, com a
  mesma validade, a caixa já aberta: abrir uma segunda tendo uma aberta é garantir que uma se
  estraga. O que não tem validade conhecida vai para o fim.
- **A substância ativa é campo próprio e indexado.** Sem catálogo externo, é a única maneira
  de "Brufen" e "ibuprofeno" se encontrarem um ao outro.
- **A posologia é texto livre**, como veio escrita. Interpretá-la em campos estruturados é
  inventar precisão que a receita não tem.
- **Nada do que se deriva é guardado** — validade efetiva, stock utilizável e ordem de uso
  são sempre calculados a partir dos valores em bruto.

### Uma só superfície de API

A interface web usa exactamente os mesmos endpoints OCS que um agente externo usaria. O que o
agente consegue fazer é, por construção, o que a UI faz.

## API

Autenticação por **app password** (Definições → Segurança → Criar nova app password), com o
cabeçalho `OCS-APIRequest: true` em todos os pedidos.

```bash
curl -u 'utilizador:app-password' -H 'OCS-APIRequest: true' \
  https://nuvem.exemplo/ocs/v2.php/apps/medcabinet/api/v1/help
```

O `/help` é público e descreve a API toda — conceitos, endpoints e as armadilhas do domínio.

### Começar

```bash
BASE=https://nuvem.exemplo/ocs/v2.php/apps/medcabinet/api/v1
AUTH='-u utilizador:app-password -H OCS-APIRequest:true -H Content-Type:application/json'

curl $AUTH -X POST "$BASE/people" -d '{"name": "Maria"}'

# Um xarope: a forma e os dias após abertura são o que impede o aviso de mentir
curl $AUTH -X POST "$BASE/medicines" -d '{
  "name": "Ben-u-ron", "substance": "paracetamol", "strength": "40 mg/ml",
  "form": "xarope", "unit": "ml", "daysAfterOpening": 28
}'

curl $AUTH -X POST "$BASE/medicines/1/packages" -d '{
  "unitsTotal": 100, "expiresAt": "2028-05-31", "location": "gaveta da cozinha"
}'

# Ao abrir
curl $AUTH -X PUT "$BASE/packages/1" -d '{"openedAt": "2026-10-07"}'

# Para que serviu
curl $AUTH -X POST "$BASE/episodes" -d '{
  "reason": "febre", "personId": 1, "startedAt": "2026-10-07",
  "prescriber": "Dr. X",
  "items": [{"medicineId": 1, "posology": "5 ml de 8 em 8 horas, 3 dias"}]
}'

curl $AUTH "$BASE/medicines/1/uses"
curl $AUTH "$BASE/episodes?q=febre"
curl $AUTH "$BASE/overview"
```

## Registar uma caixa por leitura

As embalagens de medicamentos sujeitos a receita trazem um **DataMatrix** com o código do
produto, o lote e a **validade** — precisamente os dados chatos e arriscados de escrever à
mão. O nome não vem lá, mas esse lê-se na caixa num segundo.

```bash
# Interpretar o conteúdo do código (de qualquer app de leitura do telefone)
curl $AUTH -X POST "$BASE/scan/code" -d '{"payload": "010560123456789717280331"}'

# O texto lido da caixa -- as regras daqui e que lhe encontram os campos
curl $AUTH -X POST "$BASE/scan/text" -d '{"text": "BEN-U-RON 500 mg Val.: 03/2028"}'

# Varias leituras da mesma caixa juntas (o nome esta na frente, a validade atras)
curl $AUTH -X POST "$BASE/scan/merge" -d '{"observations": [
  {"text": "BEN-U-RON paracetamol 500 mg", "from": "frente"},
  {"text": "Lote AB1234 Val 03/2028", "from": "painel de tras"}
]}'

# Gravar, depois de revisto
curl $AUTH -X POST "$BASE/scan/apply" -d '{"values": {
  "name": "Brufen", "substance": "ibuprofeno", "strength": "600 mg",
  "expiry": "2028-03-31", "batch": "AB12", "unitsTotal": 20
}}'
```

### Uma proposta não é um registo

`/scan/code`, `/scan/text` e `/scan/merge` devolvem uma **proposta**, e gravar é um passo
separado de propósito. Cada campo diz de onde veio, com que confiança, e se foi confirmado:

- **do código descodificado** — exato, tem dígito de controlo; pode ser gravado sem perguntar
- **de texto** — entra em `needsReview`
- **não confirmado** — veio já extraído e o texto não o contém; entra também em `unverified`

A distinção não é formalidade. Um «7» lido como «1» desloca a validade seis anos e continua
a parecer uma data perfeitamente normal — e dar uma caixa como boa dois anos depois de o
estar é exactamente o que esta app existe para evitar.

Quando duas leituras discordam, aparecem as duas em `conflicts`. Um código ganha a um texto,
porque é verificável; entre fontes igualmente fiáveis não se escolhe à sorte.

### O dia «00» do código GS1

A validade vem como `AAMMDD`, e o dia pode ser `00` — que na norma GS1 significa **fim do
mês**, não dia zero. `280300` é 31 de março de 2028. Ao pé da letra dá uma data inválida;
arredondado para o dia 1 encurta a validade um mês inteiro, e a app deitaria fora
medicamentos bons.

## Quem lê a caixa não é esta app

Esta app **não trata imagens**, de propósito. Quem fotografa a caixa e interpreta a
fotografia é o **agente** (`appsagent`), que já tem modelo de visão (`core:analyze-images`)
e descobre estas rotas sozinho por reflexão sobre os atributos `#[ApiRoute]`.

Aqui ficam o registo e as regras do domínio. A separação não é só de responsabilidades —
é o que faz as regras aguentarem-se: **quem escreve o registo é quem tem de validar.** Se a
regra vivesse no agente, um agente novo, uma versão nova do modelo ou uma chamada feita à
mão com `curl` entravam sem passar por ela. A app é o último sítio antes da base de dados.

### Três entradas, uma saída

| Endpoint | O que recebe | Confiança |
| --- | --- | --- |
| `POST /scan/code` | uma cadeia GS1 **já descodificada** | exata — tem dígito de controlo |
| `POST /scan/text` | o **texto** lido da caixa | a confirmar |
| `POST /scan/merge` | várias leituras da mesma caixa | a maior de cada campo |

```bash
# O agente leu a fotografia e manda o texto
curl -u ric:app-password -H 'OCS-APIRequest: true' \
  -d 'text=BEN-U-RON paracetamol 500 mg 20 comprimidos Lote: AB1234 Val.: 03/2028' \
  -d 'from=foto do painel de trás' \
  'https://nuvem/ocs/v2.php/apps/medcabinet/api/v1/scan/text'
```

As regras encontram no texto a validade, o lote, a dosagem, a quantidade, a forma e o
código nacional — por **expressão regular, não por modelo**. A diferença não é académica: um
modelo que «lê» uma data pode devolver uma data que não está lá; uma expressão regular sobre
o texto não inventa — ou encontra o que está escrito, ou não devolve nada.

A resposta traz `read.evidence`: para cada campo, o pedaço de texto onde ele apareceu. É a
resposta a «porque é que a validade saiu assim», e sem isso uma proposta estranha não se
consegue explicar.

### Um campo que o texto não confirme não conta como lido

O agente pode mandar em `values` os campos que já extraiu. Esses são **conferidos contra o
`text`** que ele próprio enviou: o que não aparecer lá volta em `unverified`, entra em
`needsReview`, e na interface aparece a vermelho.

```bash
curl -u ric:app-password -H 'OCS-APIRequest: true' \
  -d 'text=BEN-U-RON paracetamol 500 mg' \
  -d 'values[expiry]=2029-07-31' \
  'https://nuvem/ocs/v2.php/apps/medcabinet/api/v1/scan/text'
# -> unverified: ["expiry"]   (a data não está no texto)
```

É a regra que molda tudo isto. Um modelo a quem falta a validade na fotografia não responde
«não sei»: responde **uma data plausível**. E uma data plausível errada é exatamente o erro
que esta app existe para evitar — dar uma caixa como boa dois anos depois de o estar.

A comparação ignora pontuação, porque `03/2028`, `03-2028` e `032028` são a mesma coisa
impressa de maneiras diferentes. Valores com menos de três caracteres nunca contam como
confirmados: acertavam por acaso em qualquer texto cheio de números.

A distinção é por tipo de campo, não por desconfiança geral:

- **transcrição** (validade, lote, dosagem, quantidade, códigos) — tem de aparecer no texto;
- **classificação** (nome, substância, forma) — não precisa. Um nome errado vê-se logo; uma
  validade errada não se vê nunca.

### As regras de forma

Tudo o que chega passa por `FieldRules`, no `/scan/text`, no `/scan/merge` **e outra vez** no
`/scan/apply` — porque um agente pode chamar o `apply` directamente.

| Campo | Regra |
| --- | --- |
| `expiry` | tem de ser um dia que existe. `2028-02-30` é recusada; `2028-02` vale `2028-02-29` |
| `form` | uma das formas conhecidas, ou nada |
| `gtin` | dígito de controlo tem de bater; um GTIN errado associa a caixa ao medicamento errado da próxima vez |
| `batch` | letras e dígitos, sem espaços |
| `unitsTotal` | um número |

São regras de **forma, não de plausibilidade**. `2028-02-30` é recusada porque esse dia não
existe, não porque pareça improvável — rejeitar por parecer estranho dava uma app que discute
com quem tem a caixa na mão.

E se um campo não passa no `/scan/apply`, **nada é gravado**. Gravar a caixa sem a validade
que se achava que ia gravada deixava um registo com ar de completo, que é pior do que nenhum.

## Desenvolvimento

```bash
composer install
npm install && npm run build
composer run test:unit
```

Os testes são unitários puros e correm **sem um Nextcloud à volta** — `tests/bootstrap.php`
usa os stubs do `nextcloud/ocp` quando não encontra um servidor.

Correm sem dependências de servidor porque nenhum dos serviços testados toca em ficheiros
nem em serviços do Nextcloud — o que é consequência de a app não tratar imagens.

## Licença

AGPL-3.0-or-later
