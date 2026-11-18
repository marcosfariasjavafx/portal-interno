'use strict';

const alternarSenha = document.querySelector('.mostrar');
const senha = document.getElementById('senha');

if (alternarSenha && senha) {
    alternarSenha.hidden = false;
    alternarSenha.addEventListener('click', () => {
        const ocultar = senha.type === 'text';
        senha.type = ocultar ? 'password' : 'text';
        alternarSenha.textContent = ocultar ? 'Mostrar' : 'Ocultar';
    });
}
