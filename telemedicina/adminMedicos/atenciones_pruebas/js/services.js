function consumirAPI({ accion, metodo = "GET", datos = {} }) {

    let url = 'php/funciones.php';

    if (metodo === "GET") {

        const params = new URLSearchParams({
            accion,
            ...datos
        });

        url += "?" + params.toString();

        return fetch(url)
            .then(r => r.text())
            .then(t => {

                try {
                    return JSON.parse(t);
                } catch (e) {
                    throw new Error("No es JSON válido");
                }
            });
    }

    if (metodo === "POST") {


        return new Promise((resolve, reject) => {
            $.ajax({
                url: url,
                type: "POST",
                dataType: "json", // jQuery parsea la respuesta automáticamente
                //contentType: "application/json; charset=utf-8", // enviar JSON
                data: {
                    accion,
                    ...datos
                },
                success: function (response) {
                    resolve(response);
                },
                error: function (xhr, status, error) {
                    reject(error);
                }
            });
        });
    }
}

function loginAPI() {
    return consumirAPIExterna({
        url: 'https://inteccocorp-services.com/api/v1/auth/login',
        metodo: 'POST',
        datos: {
            userName: 'adminMD',
            password: 'admin@ccess.#'
        }
    });
}

function anularOrden(idOrden, token) {
    return consumirAPIExterna({
        url: `https://inteccocorp-services.com/api/v1/order/annulledOrder/${idOrden}`,
        metodo: 'PUT',
        headers: {
            'Authorization': 'Bearer ' + token
        }
    });
}

function consumirAPIExterna({ url, metodo = "GET", datos = {}, headers = {} }) {

    return fetch(url, {
        method: metodo,
        headers: {
            'Content-Type': 'application/json',
            ...headers
        },
        body: metodo !== "GET" ? JSON.stringify(datos) : undefined
    })
    .then(async (response) => {
        const text = await response.text();

        try {
            return JSON.parse(text);
        } catch (e) {
            return text;
        }
    });
}

async function cancelarApartado(idOrden) {
    try {

        const loginResp = await loginAPI();

        const token = loginResp.token;

        const resp = await anularOrden(idOrden, token);

        Swal.fire('', 'Se regresaron los productos al stock de la maquina', 'info');

    } catch (error) {
        console.error('Error:', error);
    }
}