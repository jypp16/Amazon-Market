# Acuerdos sobre el módulo de compras

Proyecto Amazon Market

Este documento explica cómo hemos decidido que funcione el módulo de compras y cómo se relacionará con productos, ventas e inventario. Recoge los acuerdos de la conversación, sus motivos, ejemplos y los límites que deben respetarse al desarrollarlo.

Todavía no se está modificando el código del sistema ni los documentos entregados a la profesora. Este archivo deja por escrito las decisiones para que el diseño y la programación posteriores partan de una misma idea. No significa que todas las funciones descritas ya estén implementadas.

## 1. Para qué se incorporará compras

En el proyecto anterior, la reposición se hacía cambiando las existencias desde productos. Eso permitía aumentar una cantidad, pero no dejaba un registro completo de quién entregó la mercadería, qué documento la respaldaba, cuánto se recibió ni cuánto se pagó por ella.

Compras se incorporará para registrar el abastecimiento que realmente llega al minimarket. El sistema conservará el proveedor, el documento, los artículos aceptados, sus cantidades, las presentaciones recibidas, sus importes y los datos de vencimiento que correspondan.

La compra se registrará después de revisar la entrega. Al confirmarla, sus cantidades aumentarán las existencias y se registrará el movimiento de ingreso. No será necesario volver a escribir esas cantidades en productos.

El módulo permitirá responder preguntas concretas: de qué proveedor vino un artículo, cuándo se recibió, cuántas unidades ingresaron y cuánto costó esa recepción. Su propósito es apoyar el control interno del negocio.

## 2. Qué se registrará y qué queda fuera

Se registrará mercadería que ya llegó, fue revisada y quedó aceptada. No se registrarán órdenes de compra ni pedidos pendientes de entrega.

El dueño o encargado revisa físicamente la cantidad, el estado de los envases y las fechas antes del registro. Si hay diferencias o daños, los resuelve con el proveedor y utiliza el respaldo corregido correspondiente. Después se ingresa al software el resultado final de esa revisión.

No se desarrollará en este alcance un proceso de devolución al proveedor, reclamos pendientes, reemplazos futuros ni gestión de cuentas por pagar. La operación descrita por el grupo consiste en recibir, verificar, resolver las diferencias y pagar la mercadería.

No se incorporará contabilidad completa, cálculo de obligaciones tributarias ni integración con SUNAT. Registrar los datos de una factura o boleta no significa validarla ante SUNAT ni emitirla desde el sistema.

También quedan fuera las bonificaciones y unidades gratuitas. En este alcance no se registrarán entregas adicionales sin costo. Los documentos y ejemplos utilizados deberán corresponder a compras que puedan representarse con los importes y cantidades acordados.

## 3. Productos seguirá siendo el catálogo

El módulo de productos se mantiene. Allí se identifica el artículo que el negocio vende: su nombre, código, descripción, categoría, contenido del envase, estado, presentaciones y precios de venta.

El producto no se vuelve a crear cada vez que se recibe mercadería. Si ya existe el mismo artículo, se selecciona en la nueva compra.

Por ejemplo, una lata de atún de una marca, variedad y contenido determinados sigue siendo el mismo producto aunque se compre el lunes a una distribuidora y el viernes a otra. Cambian las compras y posiblemente sus costos, pero no la identidad del artículo.

En cambio, otra marca, otra variedad o un contenido diferente deben distinguirse en el catálogo. Una lata de 170 gramos y una de 300 gramos no son el mismo artículo vendido en dos agrupaciones; son dos artículos diferentes de fábrica.

El proveedor tampoco será un dato que limite permanentemente el producto a una sola empresa. La relación entre proveedor y artículo se establece en cada compra. Así un artículo puede tener compras a varios proveedores y cada proveedor puede entregar varios artículos.

## 4. Los códigos del producto y del documento cumplen funciones distintas

El código de barras del empaque identifica una presentación comercial del artículo. Comprar el mismo artículo a otro distribuidor no obliga a crear otro código o producto.

Un código de barras no debe utilizarse para identificar al proveedor, a la compra o al lote. Si un pack o una caja tiene un código distinto del envase individual, ese código debe asociarse a la presentación correspondiente para reconocer su equivalencia; no justifica por sí solo un inventario independiente.

La referencia visible de una compra será el documento entregado por el proveedor. Por ejemplo: "Factura F001-00001234 — Distribuidora A". No se necesita mostrar al administrador un segundo código comercial de compra.

La base de datos tendrá una identificación interna para relacionar la compra con sus detalles, movimientos y correcciones. Esa identificación es parte del funcionamiento del sistema y no es un campo que el administrador deba escribir.

## 5. Cada registro de compra tendrá un proveedor y un documento

Se aceptó registrar una compra por proveedor y documento de respaldo. Dentro del registro se agregan los artículos recibidos y aceptados de ese proveedor.

Si llegan dos distribuidores, se registran sus compras por separado. Si ambos entregan el mismo producto, ambos registros apuntan al mismo artículo del catálogo.

No se exigirá asignar previamente cada producto a un proveedor exclusivo. El administrador podrá seleccionar un artículo del catálogo al registrar la compra. Su origen quedará explicado por el proveedor de esa operación.

El alcance actual no incluye varias recepciones parciales pendientes de un mismo documento. Las compras utilizadas deberán representar la entrega final verificada que se registra una vez.

## 6. Documentos que se aceptarán

Se aceptarán Factura y Boleta. Inicialmente no se adjuntará una imagen, fotografía o PDF. El administrador tendrá el documento disponible para revisarlo y escribirá sus datos en el formulario.

No habrá un campo genérico llamado "Título del documento". Se utilizarán los datos que realmente permiten identificarlo.

| Dato | Qué representa |
| --- | --- |
| Proveedor | La empresa o persona que entrega la mercadería |
| Tipo de documento | Factura o Boleta |
| Serie | La serie que figura en el documento, como F001 |
| Número | El número que acompaña a la serie |
| Fecha de emisión | La fecha escrita en el documento |
| Fecha de recepción | El día en que el minimarket recibió la mercadería |
| Total del documento | El importe final que se utilizará para comprobar el registro |

El sistema guardará automáticamente la fecha y hora de registro y el administrador responsable.

La fecha de emisión, recepción y registro pueden coincidir, pero no significan lo mismo. Un documento puede haberse emitido antes de la entrega y una recepción puede registrarse después de ocurrida.

La fecha de recepción puede aparecer inicialmente como la fecha del día para facilitar el trabajo. Debe poder indicarse la fecha real si se registra una entrega anterior. No se utilizará una fecha futura como si la mercadería ya hubiera llegado.

El registro del documento es manual y sirve para el control interno. No incluye una comprobación automática de su autenticidad ante SUNAT.

## 7. Cómo será el recorrido de registrar una compra

El administrador seleccionará el proveedor, escribirá los datos del documento y añadirá los artículos del catálogo. Por cada artículo seleccionará la presentación recibida, la cantidad, el importe correspondiente y los datos del empaque que apliquen.

El formulario mostrará la equivalencia en unidades de inventario para que el administrador pueda comprobar el ingreso. Si se escriben dos cajas de 24 latas, debe verse que ingresarán 48 latas.

Antes de confirmar, se revisan los productos, cantidades, fechas, costos y total. El total calculado debe coincidir con el documento final utilizado como respaldo, incluyendo los descuentos y redondeos acordados.

Se aceptó poder registrar un proveedor o producto nuevo sin perder la compra que se está preparando. El producto creado de esa manera comienza con existencias en cero. Su abastecimiento ingresa cuando se confirma la compra, no cuando se crea la ficha.

Las cantidades de envases y agrupaciones serán enteras. Se podrá registrar una botella, seis botellas o una caja completa; no media botella o una fracción de un envase cerrado.

Si solo parte de una caja queda aceptada, las unidades aceptadas se registran en una presentación que permita representar esa cantidad real. No se obliga a registrar una caja completa que físicamente no quedó en el negocio.

## 8. El registro de productos no tendrá stock inicial

Se retirará el campo "Stock inicial" del registro habitual de productos. Un artículo nuevo comenzará en cero y sus ingresos se realizarán desde compras.

Esto evita registrar dos veces el mismo abastecimiento. Si al crear un producto se escribieran 24 unidades y después se confirmara una compra de esas mismas 24, el sistema terminaría mostrando 48 aunque solo existieran 24 físicamente.

El stock actual sí se conserva como saldo del inventario. Se mostrará para consultar la cantidad disponible, pero no se modificará libremente desde la edición del producto.

El stock mínimo también se mantiene y se configura en productos. Es la cantidad de referencia para avisar que conviene reponer; no aumenta ni disminuye las existencias.

Si en el futuro se instala el sistema en un negocio que ya tiene mercadería, hará falta registrar un inventario inicial basado en un conteo. No se inventarán compras históricas para explicar esas existencias. Ese proceso se tratará aparte; el proyecto todavía no opera en el minimarket.

## 9. Todos los artículos incluidos serán envasados

El alcance acordado utiliza artículos que se reciben y venden cerrados, tal como vienen de fábrica. No se incluirá venta a granel, corte de piezas ni venta por peso.

El contenido indicado en el empaque forma parte de la descripción del artículo. No determina por sí solo cómo se cuenta el inventario.

| Artículo | Cómo se contará el saldo |
| --- | --- |
| Arroz marca A en bolsa de 1 kg | Número de bolsas |
| Azúcar marca B en bolsa de 1 kg | Número de bolsas |
| Leche marca C en envase de 1 litro | Número de envases |
| Atún marca D en lata de 170 g | Número de latas |
| Bebida X en botella de 500 ml | Número de botellas |
| Jamón en paquete cerrado de contenido fijo | Número de paquetes |

Diez bolsas de arroz de un kilo serán diez unidades de ese producto. Una bolsa de cinco kilos será otro artículo, porque su contenido de fábrica es diferente.

No se convertirá automáticamente una bolsa de cinco kilos en cinco bolsas de un kilo. El negocio no está fraccionando ni reenvasando ese producto.

## 10. Presentaciones y equivalencias

Un artículo podrá tener presentaciones que agrupen cantidades del mismo producto: unidad, pack o caja. Cada presentación tendrá definida su equivalencia respecto de la unidad usada para llevar el saldo.

Ejemplo ilustrativo para una cerveza específica:

| Presentación | Unidades de inventario que representa |
| --- | ---: |
| Lata | 1 lata |
| Pack de 6 | 6 latas |
| Caja de 24 | 24 latas |

La equivalencia depende del producto. Una caja de otro artículo puede contener 12, 20 o una cantidad distinta. No se utilizará una equivalencia universal para todo lo llamado "Caja".

Las presentaciones podrán utilizarse para compra, venta o ambas. Una caja que solo se recibe no necesita un precio de venta si el negocio no la vende de esa manera.

Al confirmar la compra se convierte la cantidad a unidades de inventario:

> Unidades ingresadas = cantidad de presentaciones recibidas × unidades contenidas en la presentación.

Por ejemplo, dos cajas de 24 ingresan 48 latas. La compra conserva que se recibieron dos cajas y su equivalencia, mientras el saldo se mantiene en latas.

La equivalencia utilizada debe conservarse en el detalle de esa operación. Si después cambia la configuración de una presentación, no se recalculan ni reinterpretan las compras o ventas anteriores.

## 11. Venta por unidad o pack y precios por presentación

Se aceptó un precio vigente por presentación, configurado en productos. No habrá precios distintos según el proveedor, la compra de origen o el lote.

Por ejemplo, una lata puede venderse a S/ 5 y un pack de seis a S/ 27. El precio del pack no tiene que ser seis veces el precio individual: el administrador lo decide y lo mantiene en productos.

Vender un pack de seis debe cobrar el precio de esa presentación y descontar seis unidades del saldo común. Vender una lata cobra el precio individual y descuenta una.

No se manejarán existencias independientes de "latas para unidad" y "latas para pack". Tampoco se reservará una parte del inventario para cada presentación.

Se aceptó que el negocio pueda entregar seis unidades sueltas al precio del pack. Por lo tanto, no se controlará si el empaque de agrupación sigue sellado ni se registrará su apertura.

Si quedan ocho latas, puede venderse un pack de seis y quedarán dos. Si quedan cinco, no puede venderse ese pack completo.

Estas presentaciones agrupan unidades del mismo artículo. Un paquete que mezcla artículos diferentes no forma parte de esta definición.

Aunque ahora se documenta compras, ventas tendrá que utilizar estas equivalencias cuando se desarrolle esa conexión. Añadirlas únicamente al formulario de compras no basta para cobrar y descontar packs correctamente.

## 12. Costo de compra y precio de venta

El costo de adquisición y el precio de venta cumplen funciones diferentes. El primero explica cuánto se pagó por una recepción; el segundo indica cuánto se cobra actualmente al cliente.

Cada compra conserva su costo. Si el lunes se compran diez latas a S/ 3 y el viernes otras diez a S/ 3,50, el historial conserva ambos costos en sus respectivas operaciones.

Registrar la compra del viernes no cambia el costo del lunes ni modifica automáticamente los precios de venta. El administrador decide los nuevos precios en productos.

Cuando se cambia un precio de venta, se aplica a las nuevas ventas de esa presentación, independientemente de qué recepción aportó las unidades físicas. Las ventas anteriores conservan lo realmente cobrado.

El costo de una recepción tampoco debe presentarse por sí solo como la utilidad del negocio. En este alcance no se define una valoración contable del inventario ni se calcula la utilidad neta.

## 13. Importes finales por presentación

Los costos se registrarán según la presentación recibida. Si se reciben cajas, el formulario mostrará "Costo por caja"; si se reciben packs, "Costo por pack"; si se reciben envases individuales, "Costo por unidad".

Se utilizarán importes finales que incluyen los impuestos y descuentos que correspondan. No se volverá a añadir un impuesto o descuento que ya está incluido en el importe escrito.

Ejemplo ilustrativo: si una caja de 24 cuesta finalmente S/ 96 y se reciben dos, el importe de la línea es S/ 192 y el ingreso es de 48 unidades. El equivalente de adquisición por unidad es S/ 4. Ese dato no cambia por sí mismo el precio de venta.

Cuando el documento tenga un descuento general sobre varios artículos, se aceptó repartirlo proporcionalmente entre sus importes. Las diferencias de redondeo deben ajustarse de forma definida para que el total distribuido coincida exactamente con el descuento y el total del documento.

Se conservará el importe final exacto de cada línea. El costo equivalente podrá necesitar más precisión que dos decimales. Por ejemplo, tres unidades que cuestan finalmente S/ 10 no deben quedar registradas como un total de S/ 9,99 solo por utilizar S/ 3,33 en la división.

La misma precaución se aplica al separar un artículo en varias líneas por vencimiento: el importe original se distribuye entre las cantidades correspondientes, sin repetirlo completo en cada línea.

El total del documento se registra para comprobarlo frente al resultado calculado. Si no coinciden, se revisan cantidades, importes, descuentos y redondeos antes de confirmar. No se cambia arbitrariamente el total para ocultar la diferencia.

Las reglas exactas de precisión y distribución de centavos se detallarán en el diseño de cálculos. El acuerdo de negocio es que los importes finales sean consistentes y no cambien lo realmente pagado.

## 14. Lote informativo del empaque

Después de revisar la diferencia con la planificación presentada, se aceptó conservar el lote únicamente como información de la mercadería recibida. Esta decisión reemplaza la propuesta anterior de eliminarlo completamente.

El lote es el código indicado en el empaque que identifica un grupo del producto. No identifica al proveedor y no se obtiene del número de factura.

Dos proveedores pueden entregar el mismo lote y un mismo proveedor puede entregar varios lotes. Una compra también puede contener más de un lote del mismo artículo.

Se escribirá el lote que realmente figura en el empaque. No se utilizará el código de la compra como sustituto para aparentar que se conoce el lote del fabricante.

Si el mismo artículo llega con lotes distintos, se separan sus líneas para conservar esa información. Sin embargo, sus cantidades aumentan el mismo saldo del producto.

El lote permitirá consultar qué se recibió y ayudar al administrador a ubicar la mercadería físicamente. No se llevará un saldo independiente por lote, no se elegirá lote al vender y no se fijarán precios por lote.

Falta especificar el tratamiento excepcional de un lote que no exista o no pueda leerse, porque la planificación lo presenta como obligatorio. Esa excepción no se resolverá inventando un dato ni suponiendo una decisión que el grupo no haya tomado.

## 15. Vencimiento por línea de recepción

Los vencimientos se conservarán en los detalles de compra, no como una única fecha general del producto. El mismo artículo puede tener mercadería recibida con distintas fechas.

Se aceptó separar las cantidades por vencimiento. Ejemplo ilustrativo:

| Producto | Cantidad recibida | Vencimiento |
| --- | ---: | --- |
| Leche X | 12 envases | 15/03/2027 |
| Leche X | 8 envases | 20/04/2027 |

El ingreso total es de veinte unidades. Las líneas conservan las dos fechas para consulta y alertas.

Si una caja contiene unidades con distintos vencimientos, se desglosan las cantidades en unidades o en una presentación que represente correctamente cada grupo. No se coloca una única fecha que oculte la diferencia.

En productos se configurará si el artículo requiere fecha de vencimiento. Cuando la requiere, no se confirma una línea sin fecha válida. Cuando realmente no corresponde, se muestra "No aplica". Esa opción no se utilizará para evitar completar un dato obligatorio.

Se aceptó comparar el vencimiento con la fecha de recepción. La fecha debe ser posterior a la recepción. Una fecha anterior o igual se rechaza, siguiendo la restricción acordada. Debe ser una fecha real y no un texto o una fecha inexistente.

## 16. Rotación física a cargo del administrador

El administrador revisa las fechas y coloca primero para la venta los productos que vencen antes. Cuando tienen el mismo vencimiento, prioriza los recibidos antes.

Este orden se basa primero en el vencimiento. No se presupone que lo que llegó antes siempre vence antes: una entrega más reciente podría tener una fecha más cercana.

La colocación en estantes y la elección física del envase corresponden al administrador o al personal que organiza la mercadería. El sistema conserva información y muestra avisos, pero no verifica qué envase se entrega al cliente.

Las ventas descontarán del total del producto. No asignarán las salidas a una recepción, lote o vencimiento específico.

## 17. Qué podrá informar el sistema sobre vencimientos

El sistema podrá informar qué fechas y lotes se recibieron, sus cantidades originales, proveedor y documento. También podrá mostrar el saldo actual total del producto.

No podrá afirmar cuántas unidades quedan de una recepción o de un vencimiento determinado. Si se recibieron doce envases con una fecha, después de varias ventas no se sabe cuántos de esos doce siguen disponibles.

Por eso las alertas serán avisos de revisión física y no cantidades confirmadas próximas a vencer. El sistema tampoco podrá bloquear con exactitud la venta de un lote vencido sin identificar cuál se está entregando.

Este límite debe aparecer en la explicación del proyecto. Conservar datos de entrada no equivale a seguir cada grupo de unidades hasta su salida.

## 18. Alertas de vencimiento

Se aceptó mostrar avisos con quince días de anticipación. Durante ese período se indicará que corresponde revisar físicamente la mercadería relacionada con la recepción.

Ejemplo de mensaje:

> Revisar leche X recibida en la factura F001-00001234. Vencimiento registrado: 15/03/2027.

La alerta no dirá que quedan doce unidades con ese vencimiento, porque doce fue la cantidad recibida y puede haberse vendido total o parcialmente.

Si la fecha ya pasó, el aviso señalará que se revise el vencimiento registrado. No se descontará stock ni se registrará una baja automáticamente por el paso del tiempo.

Se aceptó permitir "Marcar como revisado". La acción conservará fecha, administrador y una observación, por ejemplo: "Se revisó y ya no quedan unidades de esa recepción".

Marcar el aviso como revisado no modifica las existencias. Si se encuentran artículos vencidos y se retiran, esa salida debe registrarse mediante la operación de baja que corresponda.

Cuando el saldo total del producto sea cero, el aviso puede ocultarse, conservando los datos históricos. Si cambia una fecha por una corrección, su aviso debe actualizarse y no conservar como vigente una fecha equivocada.

El estado de revisión de una recepción anterior no debe ocultar avisos de otras recepciones del mismo artículo. Cada vencimiento registrado tiene su propia referencia de revisión.

## 19. Alertas de bajo stock

Se aceptó que la alerta aparezca cuando el saldo del producto sea menor o igual al stock mínimo. Dejará de aparecer cuando el saldo supere ese mínimo.

Si el stock mínimo es seis y quedan seis unidades, se muestra la alerta. Si después de una compra quedan veinte, se retira. Si las ventas vuelven a reducirlo a seis o menos, aparece nuevamente.

El saldo y el mínimo se expresarán en la misma unidad de inventario. Si una cerveza se controla en latas, el mínimo también se define en latas, no mezclando latas con cajas.

Esta alerta puede calcularse con el saldo real que mantiene el sistema. A diferencia del aviso de vencimiento, no necesita estimar a qué recepción pertenecen las unidades restantes.

## 20. Confirmación de la compra

Antes de confirmar se comprueba que los datos obligatorios estén completos, que el proveedor y los artículos sean válidos, que las cantidades representen envases enteros y que las presentaciones tengan equivalencias correctas.

También se revisan los importes, vencimientos obligatorios y la identificación del documento. Las validaciones deben aplicarse al guardar, además de las ayudas visibles del formulario.

Una compra confirmada guarda su cabecera, detalles, ingresos de inventario y movimientos de Kardex como una sola operación. Kardex será el registro de movimientos que permite explicar los cambios del inventario.

Cada ingreso estará relacionado con su producto, cantidad en unidades de inventario, compra de origen, fecha y administrador. El lote y vencimiento informativos se conservarán relacionados con el detalle recibido, sin crear saldos por lote.

No se permitirá que solo algunos artículos de la compra aumenten las existencias y que el resto falle dejando una compra presentada como completa.

## 21. Fallos, reintentos y compras duplicadas

Se utilizará una transacción de base de datos para agrupar los cambios de la confirmación. Si todo se completa, se confirma. Si falla antes de finalizar, se revierten los cambios de esa operación.

Una compra que falla antes de confirmarse no será una compra válida, no aumentará las existencias y no dejará movimientos parciales. El formulario conservará los datos para corregir y reintentar.

También debe contemplarse que la compra se guarde correctamente, pero se pierda la respuesta por un problema de conexión. En ese caso, reenviar no debe generar un segundo ingreso. El sistema reconocerá que esa solicitud ya fue procesada e informará el resultado.

Deshabilitar el botón mientras se guarda ayudará a evitar pulsaciones repetidas, pero la protección debe funcionar también dentro del sistema. No dependerá únicamente del botón.

Para detectar un documento ya registrado se utilizará la combinación de proveedor, tipo, serie y número. Dos proveedores pueden tener la misma numeración sin que sean la misma compra.

Una corrección se vincula al registro existente y no se presenta como una segunda compra del mismo documento. El tratamiento de volver a registrar una referencia previamente anulada deberá especificarse antes de construir el control de duplicados.

## 22. Correcciones por errores de digitación

Se aceptó la acción "Corregir compra", exclusiva del administrador. Se utilizará para errores al seleccionar, escribir o ingresar datos. No servirá para representar una devolución real al proveedor.

La compra original no se editará silenciosamente ni se eliminará. La corrección conservará el dato anterior, el correcto, el motivo, fecha y administrador. Cuando corresponda, también registrará el cambio de existencias.

Se podrán corregir cantidades, presentaciones, costos, importes, productos seleccionados, proveedor, datos del documento y vencimientos. Una corrección de un dato informativo no debe provocar un nuevo ingreso de mercadería.

El cambio de producto retirará el ingreso atribuido al artículo equivocado y aplicará el ingreso al artículo correcto como una sola operación. No debe completarse una parte y fallar la otra.

### Ejemplo de corrección de cantidad

Se registraron 24 latas, pero realmente se recibieron veinte. Después se vendieron cinco y el sistema muestra diecinueve.

La corrección resta las cuatro unidades ingresadas de más. El saldo queda en quince: veinte recibidas menos cinco vendidas.

No se registra otra compra de veinte ni se reemplaza el saldo actual directamente por veinte. Ambas acciones perderían el efecto de las ventas o duplicarían el ingreso.

### Ejemplo de corrección de costo

Se registraron diez unidades a S/ 3,50, pero el respaldo indica S/ 3. La corrección lleva el importe de S/ 35 a S/ 30. La cantidad recibida no cambia y no se modifica el stock.

La corrección tampoco cambia por sí sola el precio de venta configurado en productos.

### Comprobaciones antes de aplicar una corrección

Las correcciones deben revisar el saldo actual y las operaciones relacionadas. No se permite dejar existencias negativas ni inventar unidades para hacer posible una corrección.

Si ya hubo ventas y el cambio no puede aplicarse de forma consistente, se detiene y se revisa la situación física. Tener saldo suficiente es una comprobación necesaria cuando se retiran cantidades, pero no sustituye explicar por qué la corrección corresponde.

En el historial se verá el registro inicial y sus correcciones. En los totales se utilizará el resultado vigente, sin contar una corrección como otra compra ni perder la evidencia original.

## 23. Anulación de una compra

Se aceptó contemplar "Anular compra" para un registro que realmente no debía existir. No será un borrado y no se utilizará para devolver mercadería al proveedor.

La compra quedará visible como anulada, con motivo, fecha y administrador. Sus efectos se revertirán mediante una operación identificada cuando sea válido hacerlo.

No se podrá anular nuevamente una compra ya anulada. Tampoco se permitirán reversiones que generen existencias negativas o ignoren operaciones posteriores que necesiten revisión.

Una compra real cuya mercadería después se dañó no se anula por ese motivo. El abastecimiento sí ocurrió; la retirada corresponde a una baja posterior.

## 24. Administrador y ajustes de inventario

El administrador será el único autorizado para registrar, confirmar, corregir y anular compras, y para efectuar ajustes de existencias.

El stock actual no se cambiará libremente desde productos. Una diferencia encontrada mediante conteo se registrará como un ajuste identificado, con cantidad, motivo, fecha y responsable.

Se distinguen tres situaciones:

| Situación | Operación que la explica |
| --- | --- |
| Se digitó mal una compra | Corrección vinculada a la compra |
| El conteo físico difiere del saldo del sistema | Ajuste de inventario con motivo |
| Mercadería ingresada correctamente se dañó o venció y se retira | Baja o merma en su módulo correspondiente |

Aunque cambien existencias, sus motivos son distintos. Separarlos evita ocultar pérdidas como errores de digitación o modificar una compra que realmente ocurrió.

Las diferencias detectadas antes de aceptar la entrega se resuelven con el proveedor y no se registran como daños ocurridos en la tienda.

## 25. Consulta del historial

El historial permitirá buscar compras por proveedor y rango de fechas. Mostrará la referencia de Factura o Boleta y permitirá abrir el detalle.

En el detalle se conservarán las presentaciones recibidas, sus cantidades, equivalencias, importes finales, lotes informativos y vencimientos. Se podrán distinguir los valores registrados inicialmente y las correcciones posteriores.

Las compras anuladas se identificarán como tales. No se mezclarán con las compras válidas de forma que inflen los totales.

El listado debe manejar búsquedas sin resultados con un mensaje claro y permitir consultar un historial amplio mediante paginación. La consulta no debe perder líneas ni sumar dos veces un artículo porque tenga varios vencimientos o correcciones.

El historial sirve para revisar abastecimientos y costos de adquisición. No se presentará como contabilidad completa ni como reporte de utilidad neta.

## 26. Relación con la planificación presentada

Los documentos entregados a la profesora se mantienen. Este archivo no los reemplaza ni afirma que se hayan actualizado.

La decisión final conserva el lote como dato de entrada para acercarse a lo planificado, pero no desarrolla existencias ni salidas por lote. Las ventas descuentan del producto general y el administrador se encarga de la rotación física.

Los avisos de vencimiento serán recordatorios de revisión, no una afirmación sobre cuántas unidades siguen disponibles con determinada fecha.

Por lo tanto, no debe afirmarse que se implementó seguimiento completo por lote. Si un criterio presentado exige esa capacidad, hay una diferencia que el grupo debe reconocer y explicar. Guardar el código en una compra no equivale a seguirlo hasta la venta.

También se han precisado las presentaciones, los importes finales, las correcciones y las excepciones de vencimiento. Al preparar el diseño se comprobará su relación con los criterios existentes, sin modificar silenciosamente lo entregado.

## 27. Comprobaciones previstas para el desarrollo

Los siguientes casos sirven para comprobar el comportamiento acordado. Todavía no son resultados de pruebas ejecutadas.

| Caso | Resultado esperado |
| --- | --- |
| Crear un producto nuevo | Comienza con saldo cero |
| Recibir dos cajas de 24 | Ingresa 48 unidades al producto |
| Recibir el mismo artículo con dos vencimientos | Guarda líneas separadas y suma ambas al saldo general |
| Vender un pack de seis | Cobra el precio de la presentación y descuenta seis unidades |
| Disponer de cinco unidades e intentar vender un pack de seis | Impide completar esa venta por falta de existencias |
| Cambiar un precio de venta | Afecta nuevas ventas, no las anteriores |
| Cambiar una equivalencia | No reinterpreta operaciones históricas |
| Recibir una respuesta perdida y reenviar | Reconoce la operación guardada, sin duplicar ingresos |
| Fallar durante la confirmación | No deja compra, stock o Kardex parcialmente actualizados |
| Repetir el documento del mismo proveedor | Detecta el duplicado |
| Corregir 24 unidades a veinte | Aplica una disminución de cuatro, respetando las demás operaciones |
| Corregir solo el costo | Mantiene las cantidades disponibles |
| Intentar anular dos veces | Impide una segunda reversión |
| Llegar a quince días antes del vencimiento | Genera un aviso para revisión física |
| Marcar un aviso como revisado | Conserva la revisión y no cambia existencias |
| Alcanzar el stock mínimo | Muestra la alerta de bajo stock |
| Superar el mínimo después de un ingreso | Retira la alerta de bajo stock |
| Aplicar un descuento o dividir una línea por fechas | Conserva el total final y distribuye correctamente los importes |

## 28. Qué se precisará en el siguiente paso

El funcionamiento principal ya está acordado. El siguiente trabajo será convertirlo en campos, pantallas, relaciones de datos, validaciones y ejemplos de cálculo, sin agregar módulos nuevos por iniciativa propia.

Antes de programar deben concretarse estas excepciones y detalles:

- Qué registrar si el lote del empaque falta o no puede leerse, considerando la obligación del documento presentado.
- Cómo se habilita la corrección o el registro de un documento cuya compra fue anulada, manteniendo el control de duplicados y la relación histórica.
- Qué correcciones requieren revisión adicional por movimientos posteriores y cómo se informa al administrador que no pueden aplicarse directamente.
- La precisión de los costos equivalentes y la regla de reparto del último centavo en descuentos y líneas separadas.
- Cómo conservar los datos del documento utilizados en una compra si después cambia la información del proveedor.
- Cómo se organizarán en las pantallas las presentaciones, los vencimientos, las revisiones y las correcciones.

Estos puntos no cambian el propósito del módulo. Evitan que los casos excepcionales se resuelvan de manera distinta en cada parte del código.

## Referencias utilizadas para orientar las decisiones

Las reglas descritas son los acuerdos del grupo en esta conversación. Las siguientes referencias se utilizaron como apoyo para distinguir conceptos y conocer formas de resolverlos; no establecen que todos los minimarkets trabajen igual.

- [ERPNext sobre venta con distintas unidades, equivalencias y precios](https://docs.frappe.io/erpnext/Selling-in-different-UOM).
- [Odoo sobre presentaciones y empaques por producto](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/configure/packaging.html).
- [GS1 sobre identificación y seguimiento por lote](https://www.gs1.org/standards/gs1-global-traceability-standard/current-standard).
- [Odoo sobre rotación según fecha de ingreso o vencimiento](https://www.odoo.com/documentation/saas-15.2/applications/inventory_and_mrp/inventory/routes/strategies/removal.html).
- [MySQL sobre confirmación y reversión de transacciones](https://dev.mysql.com/doc/refman/8.4/en/innodb-autocommit-commit-rollback.html).
