/**
 * Gestion de l'Infinite Scroll pour les publications
 */
document.addEventListener('DOMContentLoaded', () => {
    const loader = document.getElementById('posts-loader');
    const contentContainer = document.querySelector('.news .content');
    
    if (!loader || !contentContainer) return;

    let currentPage = 1;
    const totalPages = parseInt(loader.dataset.totalPages);
    let isLoading = false;

    const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && !isLoading && currentPage < totalPages) {
            loadMorePosts();
        }
    }, { threshold: 0.1 });

    observer.observe(loader);

    async function loadMorePosts() {
        isLoading = true;
        currentPage++;
        
        try {
            const response = await fetch(`user_page.php?page=${currentPage}&ajax=1`);
            if (!response.ok) throw new Error('Erreur réseau');
            
            const html = await response.text();
            
            if (html.trim() !== "") {
                // Insérer les nouveaux posts avant le loader
                loader.insertAdjacentHTML('beforebegin', html);
                
                // Ré-initialiser les composants JS pour les nouveaux posts
                if (typeof initAudioVisualizers === 'function') {
                    initAudioVisualizers();
                }
                if (typeof initCommentForms === 'function') {
                    initCommentForms();
                }
            }
            
            if (currentPage >= totalPages) {
                loader.style.display = 'none';
            }
        } catch (error) {
            console.error('Erreur lors du chargement des posts:', error);
        } finally {
            isLoading = false;
        }
    }

    // Gestion de la pagination pour les utilisateurs dans bomoto
    const loadMoreUsersBtn = document.getElementById('load-more-users');
    const usersList = document.getElementById('users-list');
    let currentUserPage = 1;

    if (loadMoreUsersBtn && usersList) {
        const totalUserPages = parseInt(loadMoreUsersBtn.dataset.totalPages);
        
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
                console.error('Erreur lors du chargement des utilisateurs:', error);
                loadMoreUsersBtn.disabled = false;
                loadMoreUsersBtn.textContent = 'Réessayer';
            }
        });
    }

    // Gestion du chargement des commentaires
    document.addEventListener('click', async (e) => {
        if (e.target.classList.contains('load-more-comments-btn')) {
            const btn = e.target;
            const postId = btn.dataset.postId;
            const totalPages = parseInt(btn.dataset.totalPages);
            let currentPage = parseInt(btn.dataset.currentPage);
            
            currentPage++;
            btn.disabled = true;
            btn.textContent = 'Chargement...';

            try {
                const response = await fetch(`user_page.php?post_id=${postId}&c_page=${currentPage}&ajax_comments=1`);
                if (!response.ok) throw new Error('Erreur réseau');
                
                const html = await response.text();
                const commentsContainer = document.getElementById(`comments-section-${postId}`);
                commentsContainer.insertAdjacentHTML('beforeend', html);

                // Ré-initialiser les visualiseurs audio pour les nouveaux commentaires
                if (typeof initAudioVisualizers === 'function') {
                    initAudioVisualizers();
                }

                btn.dataset.currentPage = currentPage;
                if (currentPage >= totalPages) {
                    btn.remove();
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Afficher plus de commentaires';
                }
            } catch (error) {
                console.error('Erreur commentaires:', error);
                btn.disabled = false;
                btn.textContent = 'Réessayer';
            }
        }
    });
});