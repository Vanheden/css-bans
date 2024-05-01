import axios from 'axios';
import {appendTableData, formatDuration} from '../utility/utility';
import {ServerInfo} from '../Interface/ServerInfo';
import {showLoader} from "../utility/utility";
import {hideLoader} from "../utility/utility";

// Make a GET request to fetch mutes data
showLoader('server_list_loader');
axios.get(serversListUrl)
    .then(response => {
        // Handle successful response
        hideLoader('server_list_loader');
        appendTableData(constructTableRows(response.data), 'serverList');
    })
    .catch(error => {
        // Handle error
        hideLoader('server_list_loader');
        console.error('Error:', error);
    });

// Function to construct table rows dynamically
function constructTableRows(data: any[]): string {
    let html = '';

    data.forEach((item: ServerInfo, index) => {
        html += `
      <tr>
        <td>${item.name}</td>
        <td>
            <a href="#" class="view-players">
                <i class="fas fa-eye" data-server-id="${item.id}"></i>
            </a>
            ${item.players}
        </td>
        <td>${item.ip}</td>
        <td>${item.port}</td>
        <td>${item.map}</td>
        <td>${item.connect_button}</td>
      </tr>
    `;
    });

    return html;
}

// Add event listener for view players button
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('fa-eye')) {
        event.preventDefault();
        const serverId = event.target.dataset.serverId;
        if (serverId) {
            fetchPlayers(serverId);
        } else {
            console.error('Server ID not found.');
        }
    }
    if (event.target.parentNode.classList.contains('player')) {
        event.preventDefault();
        const playerName = event.target.parentNode.dataset.playerName;
        const action = event.target.parentNode.dataset.action;
        const server = event.target.parentNode.dataset.serverId;
        playerAction(playerName, action, server);
    }
});

// Function to fetch players for a specific server
function fetchPlayers(serverId: string) {
    showLoader();
    const playersUrl = getPlayerInfoUrl(serverId);
    axios.get(playersUrl)
        .then(response => {
            $("#modalBody").html(response.data);
            $("#modal").modal('show');
            hideLoader();
        })
        .catch(error => {
            console.error('Error fetching players:', error);
        });
}

function playerAction(playerName: string, action: string, serverId: string) {
    showLoader();
    $.ajax({
        url: playerActionUrl,
        type: 'POST',
        dataType: 'json',
        data: {
            name: playerName,
            action: action,
            serverId: serverId
        },
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            hideLoader();
            $("#"+playerName).remove();
            toastr.success('Player '+action+' successful.');
        },
        error: function(xhr, status, error) {
            hideLoader();
            toastr.error('Failed to perform action!. Either RCON PORT NOT OPEN OR RCON PASSWORD IS INCORRECT');
        }
    });
}







