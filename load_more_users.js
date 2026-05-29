document.addEventListener('DOMContentLoaded', () => {
    const loadMoreUsersBtn = document.getElementById('load-more-users');
    const usersList = document.getElementById('users-list');
    let currentUserPage = 1;

    if (loadMoreUsersBtn && usersList) {
        const totalUserPages = parseInt(loadMoreUsersBtn.dataset.totalPages, 10);

        loadMoreUsersBtn.addEventListener('click', async () => {
            currentUserPage++;
            loadMoreUsersBtn.disabled = true;
            loadMoreUsersBtn.textContent = 'Chargement...';

            try {
                const response = await fetch(`user_page.php?user_page=${currentUserPage}&ajax_users=1`);
                if (!response.ok) throw new Error('Erreur réseau');

                const html = await response.text();
                usersList.insertAdjacentHTML('beforeend', html);

                if (currentUserPage >= totalUserPages) {
                    loadMoreUsersBtn.style.display = 'none';
                } else {
                    loadMoreUsersBtn.disabled = false;
                    loadMoreUsersBtn.textContent = 'Afficher plus';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des utilisateurs :', error);
                loadMoreUsersBtn.disabled = false;
                loadMoreUsersBtn.textContent = 'Réessayer';
            }
        });
    }
});
