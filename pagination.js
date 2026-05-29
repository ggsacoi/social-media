/**
 * Gestion de l'Infinite Scroll pour les publications
 */
document.addEventListener('DOMContentLoaded', () => {
    const loader = document.getElementById('posts-loader');
    const contentContainer = document.querySelector('.news .content');

    let currentPage = 1;
    let isLoading = false;

    if (loader && contentContainer) {
        const totalPages = parseInt(loader.dataset.totalPages);

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

                if (typeof reorderTaggedComments === 'function') {
                    reorderTaggedComments(commentsContainer);
                }

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