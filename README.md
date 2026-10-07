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

## Desenvolvimento

```bash
composer install
npm install && npm run build
composer run test:unit
```

Os testes são unitários puros sobre `CabinetService` e correm **sem um Nextcloud à volta** —
`tests/bootstrap.php` usa os stubs do `nextcloud/ocp` quando não encontra um servidor.

## Licença

AGPL-3.0-or-later
